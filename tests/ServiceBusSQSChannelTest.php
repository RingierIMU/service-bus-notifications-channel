<?php

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Carbon\Carbon;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusSQSChannel;
use Ringierimu\ServiceBusNotificationsChannel\Tests\TestNotification;

// Default queue URL ends in .fifo so the existing happy-path tests exercise
// the FIFO code path (MessageGroupId/MessageDeduplicationId).
function makeSqsConfig(array $overrides = []): array
{
    return array_merge(
        [
            'enabled' => true,
            'node_id' => '123456789',
            'from' => 'test-node',
            'version' => '2.0.0',
            'dont_report' => [],
            'sqs' => [
                'region' => 'us-east-1',
                'key' => 'fake-key',
                'secret' => 'fake-secret',
                'queue_url' => 'https://sqs.us-east-1.amazonaws.com/123/test-queue.fifo',
            ],
        ],
        $overrides,
    );
}

// Event 'from' resolves via the event's own config — services.service_bus from TestCase,
// whose node_id is '123456789' and no explicit 'from'. So MessageGroupId always begins with this.
const SQS_TEST_EVENT_FROM = '123456789';

function makeKnownNotification(string $eventType, array $payload = []): Notification
{
    return new class ($eventType, $payload) extends Notification {
        public function __construct(
            public string $eventType,
            public array $payload,
        ) {
        }

        public function toServiceBus()
        {
            $event = ServiceBusEvent::create($this->eventType)
                ->withRoute('api')
                ->createdAt(Carbon::now());

            if (!empty($this->payload)) {
                $event->withPayload($this->payload);
            }

            return $event;
        }
    };
}

function mockSqsCapturingSendMessage(&$captured): SqsClient
{
    // Use andReturnUsing so the side-effect capture only fires when the expectation
    // is actually fulfilled — Mockery::on closures get evaluated during matcher
    // dispatch, which corrupts captures across multi-expectation mocks.
    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock
        ->shouldReceive('sendMessage')
        ->once()
        ->andReturnUsing(
            function ($args) use (&$captured) {
                $captured = $args;

                return new Result(['MessageId' => 'test-msg-id']);
            },
        );

    return $sqsMock;
}

function makeAwsCredentialError(string $code = 'ExpiredToken'): AwsException
{
    return new AwsException(
        "AWS error: $code",
        Mockery::mock(CommandInterface::class),
        ['code' => $code, 'message' => "Simulated $code"],
    );
}

it(
    'sends message to SQS successfully',
    function () {
        Log::spy();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                Mockery::on(
                    function ($args) {
                        return isset($args['QueueUrl'])
                            && $args['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123/test-queue.fifo'
                            && isset($args['MessageBody'])
                            && json_decode($args['MessageBody'], true) !== null
                            && isset($args['MessageGroupId']);
                    },
                ),
            )
            ->andReturn(new Result(['MessageId' => 'test-msg-id']));

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);

        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification(
                'ListingCreated',
                [
                    'listing' => ['reference' => 'L-1'],
                ],
            ),
        );

        Log::shouldHaveReceived('info')->once();
    },
);

it(
    'logs debug when disabled and event not in dont_report',
    function () {
        Log::shouldReceive('debug')
            ->once()
            ->with(
                Mockery::pattern('/test.*\[disabled\]/'),
                Mockery::type('array'),
            );

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldNotReceive('sendMessage');

        $channel = new ServiceBusSQSChannel(
            makeSqsConfig(['enabled' => false, 'dont_report' => []]),
            $sqsMock,
        );

        $channel->send(new AnonymousNotifiable(), new TestNotification());
    },
);

it(
    'suppresses log when disabled and event in dont_report',
    function () {
        Log::shouldReceive('debug')->never();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldNotReceive('sendMessage');

        $channel = new ServiceBusSQSChannel(
            makeSqsConfig(['enabled' => false, 'dont_report' => ['test']]),
            $sqsMock,
        );

        $channel->send(new AnonymousNotifiable(), new TestNotification());
    },
);

it(
    'builds MessageGroupId from the primary-entity reference per event-type taxonomy',
    function (string $eventType, array $payload, string $expectedAppend) {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(new AnonymousNotifiable(), makeKnownNotification($eventType, $payload));

        expect($captured['MessageGroupId'])
            ->toEqual(SQS_TEST_EVENT_FROM . '_' . $expectedAppend);
    },
)->with(
    [
        // listing taxonomy → listing={reference}
        'ListingCreated' => ['ListingCreated', ['listing' => ['reference' => 'L1']], 'listing=L1'],
        'ListingUpdated' => ['ListingUpdated', ['listing' => ['reference' => 'L2']], 'listing=L2'],
        'ListingDeleted' => ['ListingDeleted', ['listing' => ['reference' => 'L3']], 'listing=L3'],
        'ListingLeadCreated' => ['ListingLeadCreated', ['listing' => ['reference' => 'L4']], 'listing=L4'],
        'ListingPromoted' => ['ListingPromoted', ['listing' => ['reference' => 'L5']], 'listing=L5'],
        'ListingProductsAdded' => ['ListingProductsAdded', ['listing' => ['reference' => 'L6']], 'listing=L6'],
        'ListingProductsRemoved' => ['ListingProductsRemoved', ['listing' => ['reference' => 'L7']], 'listing=L7'],
        'ListingShared' => ['ListingShared', ['listing' => ['reference' => 'L8']], 'listing=L8'],
        'ListingFavouriteCreated' => ['ListingFavouriteCreated', ['listing' => ['reference' => 'L9']], 'listing=L9'],
        'ListingFavouriteRemoved' => ['ListingFavouriteRemoved', ['listing' => ['reference' => 'LA']], 'listing=LA'],

        // topic taxonomy → topic={reference}
        'TopicCreated' => ['TopicCreated', ['topic' => ['reference' => 'T1']], 'topic=T1'],
        'TopicUpdated' => ['TopicUpdated', ['topic' => ['reference' => 'T2']], 'topic=T2'],
        'TopicDeleted' => ['TopicDeleted', ['topic' => ['reference' => 'T3']], 'topic=T3'],

        // alert taxonomy → user_alert={alert.user.reference}
        'AlertCreated' => ['AlertCreated', ['alert' => ['user' => ['reference' => 'U-A1']]], 'user_alert=U-A1'],
        'AlertUpdated' => ['AlertUpdated', ['alert' => ['user' => ['reference' => 'U-A2']]], 'user_alert=U-A2'],
        'AlertDeleted' => ['AlertDeleted', ['alert' => ['user' => ['reference' => 'U-A3']]], 'user_alert=U-A3'],
        'AlertSent' => ['AlertSent', ['alert' => ['user' => ['reference' => 'U-A4']]], 'user_alert=U-A4'],

        // advertiser taxonomy → advertiser={reference}
        'AdvertiserCreated' => ['AdvertiserCreated', ['advertiser' => ['reference' => 'AD1']], 'advertiser=AD1'],
        'AdvertiserUpdated' => ['AdvertiserUpdated', ['advertiser' => ['reference' => 'AD2']], 'advertiser=AD2'],
        'AdvertiserDeleted' => ['AdvertiserDeleted', ['advertiser' => ['reference' => 'AD3']], 'advertiser=AD3'],
        'AdvertiserProductsAdded' => ['AdvertiserProductsAdded', ['advertiser' => ['reference' => 'AD4']], 'advertiser=AD4'],
        'AdvertiserProductsRemoved' => ['AdvertiserProductsRemoved', ['advertiser' => ['reference' => 'AD5']], 'advertiser=AD5'],
        'AdvertiserLeadCreated' => ['AdvertiserLeadCreated', ['advertiser' => ['reference' => 'AD6']], 'advertiser=AD6'],

        // user taxonomy → user={reference}
        'UserCreated' => ['UserCreated', ['user' => ['reference' => 'U1']], 'user=U1'],
        'UserUpdated' => ['UserUpdated', ['user' => ['reference' => 'U2']], 'user=U2'],
        'UserDeleted' => ['UserDeleted', ['user' => ['reference' => 'U3']], 'user=U3'],
        'UserLogin' => ['UserLogin', ['user' => ['reference' => 'U4']], 'user=U4'],
        'UserLogout' => ['UserLogout', ['user' => ['reference' => 'U5']], 'user=U5'],
        'UserPasswordRequest' => ['UserPasswordRequest', ['user' => ['reference' => 'U6']], 'user=U6'],
        'UserPasswordReset' => ['UserPasswordReset', ['user' => ['reference' => 'U7']], 'user=U7'],
        'UserVerified' => ['UserVerified', ['user' => ['reference' => 'U8']], 'user=U8'],
        'UserProductsAdded' => ['UserProductsAdded', ['user' => ['reference' => 'U9']], 'user=U9'],
        'UserProductsRemoved' => ['UserProductsRemoved', ['user' => ['reference' => 'U10']], 'user=U10'],
        'UserLeadCreated' => ['UserLeadCreated', ['user' => ['reference' => 'U11']], 'user=U11'],
        'UserAnonymized' => ['UserAnonymized', ['user' => ['reference' => 'U12']], 'user=U12'],

        // article taxonomy → article={reference}
        'ArticleCreated' => ['ArticleCreated', ['article' => ['reference' => 'AR1']], 'article=AR1'],
        'ArticleUpdated' => ['ArticleUpdated', ['article' => ['reference' => 'AR2']], 'article=AR2'],
        'ArticleDeleted' => ['ArticleDeleted', ['article' => ['reference' => 'AR3']], 'article=AR3'],

        // author taxonomy → author={reference}
        'AuthorCreated' => ['AuthorCreated', ['author' => ['reference' => 'AU1']], 'author=AU1'],
        'AuthorUpdated' => ['AuthorUpdated', ['author' => ['reference' => 'AU2']], 'author=AU2'],
        'AuthorDeleted' => ['AuthorDeleted', ['author' => ['reference' => 'AU3']], 'author=AU3'],

        // sport_event taxonomy → sport_event={reference}
        'SportEventCreated' => ['SportEventCreated', ['sport_event' => ['reference' => 'SE1']], 'sport_event=SE1'],
        'SportEventUpdated' => ['SportEventUpdated', ['sport_event' => ['reference' => 'SE2']], 'sport_event=SE2'],
        'SportEventDeleted' => ['SportEventDeleted', ['sport_event' => ['reference' => 'SE3']], 'sport_event=SE3'],

        // singleton groups (no reference appended, just a literal tag)
        'SiteLeadCreated' => ['SiteLeadCreated', [], 'site_lead'],
        'Callback' => ['Callback', [], 'callback'],
        'Error' => ['Error', [], 'callback'],
        'TestRunsDispatched' => ['TestRunsDispatched', [], 'test_run'],
        'TestRunReceived' => ['TestRunReceived', [], 'test_run'],
        'TestRunStarted' => ['TestRunStarted', [], 'test_run'],
        'TestRunComplete' => ['TestRunComplete', [], 'test_run'],
        'NewsletterSubscribed' => ['NewsletterSubscribed', [], 'newsletter'],
        'NewsletterUnsubscribed' => ['NewsletterUnsubscribed', [], 'newsletter'],
    ],
);

it(
    'partitions different listing references into distinct MessageGroupIds (FIFO grouping)',
    function () {
        Log::spy();
        $allArgs = [];
        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock
            ->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(
                function ($args) use (&$allArgs) {
                    $allArgs[] = $args;

                    return new Result(['MessageId' => 'msg-' . count($allArgs)]);
                },
            );

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingCreated', ['listing' => ['reference' => 'L-alpha']]),
        );
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingUpdated', ['listing' => ['reference' => 'L-beta']]),
        );

        expect($allArgs[0]['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM . '_listing=L-alpha');
        expect($allArgs[1]['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM . '_listing=L-beta');
        expect($allArgs[0]['MessageGroupId'])->not->toEqual($allArgs[1]['MessageGroupId']);
    },
);

it(
    'groups different event verbs for the same listing into the same MessageGroupId',
    function () {
        Log::spy();
        $allArgs = [];
        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock
            ->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(
                function ($args) use (&$allArgs) {
                    $allArgs[] = $args;

                    return new Result(['MessageId' => 'msg-' . count($allArgs)]);
                },
            );

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingCreated', ['listing' => ['reference' => 'L-shared']]),
        );
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingPromoted', ['listing' => ['reference' => 'L-shared']]),
        );

        expect($allArgs[0]['MessageGroupId'])->toEqual($allArgs[1]['MessageGroupId']);
        expect($allArgs[0]['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM . '_listing=L-shared');
    },
);

it(
    'embeds debounce_key in the SQS MessageBody for v2 events',
    function () {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification(
                'ListingCreated',
                [
                    'listing' => ['reference' => 'L-1'],
                    'user' => ['reference' => 'U-1'],
                ],
            ),
        );

        $body = json_decode($captured['MessageBody'], true);
        expect($body)->toHaveKey('debounce_key');
        expect($body['debounce_key'])->toEqual('listing=L-1_user=U-1');
    },
);

it(
    'debounce_key is independent of MessageGroupId (debounce covers full ref combo, group covers primary entity only)',
    function () {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification(
                'ListingCreated',
                [
                    'listing' => ['reference' => 'L-primary'],
                    'user' => ['reference' => 'U-secondary'],
                ],
            ),
        );

        $body = json_decode($captured['MessageBody'], true);
        expect($captured['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM . '_listing=L-primary');
        expect($body['debounce_key'])->toEqual('listing=L-primary_user=U-secondary');
    },
);

it(
    'falls back to bare from as MessageGroupId for an event type outside the taxonomy',
    function () {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('CompletelyUnknownEvent', []),
        );

        expect($captured['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM);
    },
);

// Regression: production bug — getMessageGroupId() raised "Undefined array key"
// (ErrorException 500) when a payload-keyed event arrived without the corresponding
// nested key on a .fifo queue. PHP `.` binds tighter than `??`, so
// `'user=' . $message['payload']['user']['reference'] ?? null` evaluated the array
// access first and triggered the warning before the null-coalesce could fire.
// Pinned semantic: missing nested reference → arm = null → bare `from` (matches the
// `default => null` arm and the pre-PR-#46 behaviour for unknown event types).
it(
    'returns bare from when payload-keyed event is missing its entity reference (regression: undefined array key on FIFO)',
    function (string $eventType, array $payload) {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(new AnonymousNotifiable(), makeKnownNotification($eventType, $payload));

        // Must not include any `entity=` suffix — missing nested ref → arm null → fall through.
        expect($captured['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM);
    },
)->with(
    [
        // 2-level missing: production-reported case. Payload populated but no `user` key.
        'UserAnonymized: payload exists, user key absent' => [
            'UserAnonymized',
            ['something_else' => ['reference' => 'X']],
        ],
        // payload itself empty array (no withPayload() call path).
        'UserAnonymized: empty payload' => ['UserAnonymized', []],
        // 4-level missing: deepest path in the match expression.
        'AlertSent: payload exists, alert.user.reference absent' => [
            'AlertSent',
            ['alert' => ['user' => []]],
        ],
        'AlertSent: payload exists, alert key absent' => ['AlertSent', []],
        // Sample one per remaining entity family so no precedence regression sneaks back in.
        'ListingCreated: missing listing' => ['ListingCreated', []],
        'TopicCreated: missing topic' => ['TopicCreated', []],
        'AdvertiserCreated: missing advertiser' => ['AdvertiserCreated', []],
        'ArticleCreated: missing article' => ['ArticleCreated', []],
        'AuthorCreated: missing author' => ['AuthorCreated', []],
        'SportEventCreated: missing sport_event' => ['SportEventCreated', []],
    ],
);

it(
    'sets MessageGroupId and MessageDeduplicationId on FIFO queues',
    function () {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingCreated', ['listing' => ['reference' => 'L-fifo']]),
        );

        expect($captured)->toHaveKey('MessageGroupId');
        expect($captured['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM . '_listing=L-fifo');
        expect($captured)->toHaveKey('MessageDeduplicationId');
        expect($captured['MessageDeduplicationId'])->toEqual(md5($captured['MessageBody']));
    },
);

it(
    'omits FIFO-only fields when the queue URL is not a .fifo queue',
    function () {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $config = makeSqsConfig();
        $config['sqs']['queue_url'] = 'https://sqs.us-east-1.amazonaws.com/123/standard-queue';

        $channel = new ServiceBusSQSChannel($config, $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingCreated', ['listing' => ['reference' => 'L-std']]),
        );

        expect($captured)->not->toHaveKey('MessageGroupId');
        expect($captured)->not->toHaveKey('MessageDeduplicationId');
        expect($captured['QueueUrl'])->toEqual('https://sqs.us-east-1.amazonaws.com/123/standard-queue');
        expect(json_decode($captured['MessageBody'], true))->not->toBeNull();
    },
);

it(
    'retries once on AWS credential error and succeeds on the second attempt',
    function () {
        Log::spy();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldReceive('sendMessage')
            ->once()
            ->ordered()
            ->andThrow(makeAwsCredentialError('ExpiredToken'));
        $sqsMock->shouldReceive('sendMessage')
            ->once()
            ->ordered()
            ->andReturn(new Result(['MessageId' => 'retry-msg-id']));

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(new AnonymousNotifiable(), new TestNotification());

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                Mockery::pattern('/ExpiredToken.*refreshing.*retrying/'),
                Mockery::type('array'),
            );
        Log::shouldHaveReceived('info')->once();
    },
);

it(
    'retries on each credential error code in the allowlist',
    function (string $code) {
        Log::spy();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldReceive('sendMessage')
            ->once()
            ->ordered()
            ->andThrow(makeAwsCredentialError($code));
        $sqsMock->shouldReceive('sendMessage')
            ->once()
            ->ordered()
            ->andReturn(new Result(['MessageId' => 'ok']));

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(new AnonymousNotifiable(), new TestNotification());
    },
)->with([
    'ExpiredToken',
    'ExpiredTokenException',
    'InvalidClientTokenId',
    'UnrecognizedClientException',
    'RequestExpired',
    'TokenRefreshRequired',
]);

it(
    'throws the original AwsException after exhausting credential retries',
    function () {
        Log::spy();
        $credentialError = makeAwsCredentialError('ExpiredToken');

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldReceive('sendMessage')
            ->twice()
            ->andThrow($credentialError);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);

        expect(fn () => $channel->send(new AnonymousNotifiable(), new TestNotification()))
            ->toThrow(AwsException::class, 'AWS error: ExpiredToken');
    },
);

it(
    'does not retry on non-credential AWS errors',
    function () {
        Log::spy();
        $otherError = new AwsException(
            'Throttled',
            Mockery::mock(CommandInterface::class),
            ['code' => 'ThrottlingException', 'message' => 'Rate exceeded'],
        );

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldReceive('sendMessage')
            ->once()
            ->andThrow($otherError);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);

        expect(fn () => $channel->send(new AnonymousNotifiable(), new TestNotification()))
            ->toThrow(AwsException::class, 'Throttled');

        Log::shouldNotHaveReceived('warning');
    },
);

it(
    'does not log success message when event type is in dont_report',
    function () {
        Log::spy();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Result(['MessageId' => 'silent']));

        $channel = new ServiceBusSQSChannel(
            makeSqsConfig(['dont_report' => ['test']]),
            $sqsMock,
        );
        $channel->send(new AnonymousNotifiable(), new TestNotification());

        Log::shouldNotHaveReceived('info');
    },
);

it(
    'treats missing enabled key as disabled (Arr::get returns null, null == false)',
    function () {
        Log::shouldReceive('debug')->once();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldNotReceive('sendMessage');

        $config = makeSqsConfig();
        unset($config['enabled']);

        $channel = new ServiceBusSQSChannel($config, $sqsMock);
        $channel->send(new AnonymousNotifiable(), new TestNotification());
    },
);

it(
    'treats falsy enabled values as disabled (null, false, 0, "")',
    function (mixed $value) {
        Log::shouldReceive('debug')->once();

        $sqsMock = Mockery::mock(SqsClient::class);
        $sqsMock->shouldNotReceive('sendMessage');

        $channel = new ServiceBusSQSChannel(makeSqsConfig(['enabled' => $value]), $sqsMock);
        $channel->send(new AnonymousNotifiable(), new TestNotification());
    },
)->with(
    [
        'null' => [null],
        'false' => [false],
        'zero int' => [0],
        'empty string' => [''],
    ],
);

it(
    'treats truthy enabled values as enabled (true, 1, non-empty string)',
    function (mixed $value) {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        $channel = new ServiceBusSQSChannel(makeSqsConfig(['enabled' => $value]), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingCreated', ['listing' => ['reference' => 'L-1']]),
        );

        expect($captured)->not->toBeNull();
    },
)->with(
    [
        'true' => [true],
        'one int' => [1],
        'string' => ['true'],
    ],
);

it(
    'ListingUpdated with prop-shaped payload produces non-empty MessageGroupId and debounce_key',
    function () {
        Log::spy();
        $captured = null;
        $sqsMock = mockSqsCapturingSendMessage($captured);

        // Mirrors prop's AbstractListingNotification::getPayload() output —
        // a single 'listing' entry whose inner array has a top-level 'reference'
        // (set in ListingResource::toArray as (string) $listing->id).
        $payload = [
            'listing' => [
                'reference' => '12345',
                'advertiser_reference' => '99',
                'user_reference' => '7',
                'status' => 'online',
                'title' => [
                    ['culture' => 'en_GB', 'value' => 'Test Listing'],
                ],
            ],
        ];

        $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
        $channel->send(
            new AnonymousNotifiable(),
            makeKnownNotification('ListingUpdated', $payload),
        );

        $body = json_decode($captured['MessageBody'], true);

        expect($captured['MessageGroupId'])->toEqual(SQS_TEST_EVENT_FROM . '_listing=12345');
        expect($body)->toHaveKey('debounce_key');
        expect($body['debounce_key'])->toEqual('listing=12345');
        expect($body['debounce_key'])->not->toBeEmpty();
        expect($body['debounce_key'])->not->toBeNull();
    },
);
