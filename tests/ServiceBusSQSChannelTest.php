<?php

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusSQSChannel;
use Ringierimu\ServiceBusNotificationsChannel\Tests\TestNotification;

// Default queue URL ends in .fifo so the existing happy-path tests exercise
// the FIFO code path (MessageGroupId/MessageDeduplicationId).
function makeSqsConfig(array $overrides = []): array
{
    return array_merge([
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
    ], $overrides);
}

function makeAwsCredentialError(string $code = 'ExpiredToken'): AwsException
{
    return new AwsException(
        "AWS error: $code",
        Mockery::mock(CommandInterface::class),
        ['code' => $code, 'message' => "Simulated $code"],
    );
}

it('sends message to SQS successfully', function () {
    Log::spy();

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldReceive('sendMessage')
        ->once()
        ->with(Mockery::on(function ($args) {
            return isset($args['QueueUrl'])
                && $args['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123/test-queue.fifo'
                && isset($args['MessageBody'])
                && json_decode($args['MessageBody'], true) !== null
                && isset($args['MessageGroupId']);
        }))
        ->andReturn(new Result(['MessageId' => 'test-msg-id']));

    $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);

    $channel->send(new AnonymousNotifiable(), new TestNotification());

    Log::shouldHaveReceived('info')->once();
});

it('logs debug when disabled and event not in dont_report', function () {
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
});

it('suppresses log when disabled and event in dont_report', function () {
    Log::shouldReceive('debug')->never();

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldNotReceive('sendMessage');

    $channel = new ServiceBusSQSChannel(
        makeSqsConfig(['enabled' => false, 'dont_report' => ['test']]),
        $sqsMock,
    );

    $channel->send(new AnonymousNotifiable(), new TestNotification());
});

it('sets MessageGroupId and MessageDeduplicationId on FIFO queues', function () {
    Log::spy();
    $captured = null;

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldReceive('sendMessage')
        ->once()
        ->andReturnUsing(function ($args) use (&$captured) {
            $captured = $args;

            return new Result(['MessageId' => 'msg-1']);
        });

    $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);
    $channel->send(new AnonymousNotifiable(), new TestNotification());

    expect($captured)->toHaveKey('MessageGroupId');
    expect($captured['MessageGroupId'])->toEqual('123456789');
    expect($captured)->toHaveKey('MessageDeduplicationId');
    expect($captured['MessageDeduplicationId'])->toEqual(md5($captured['MessageBody']));
});

it('omits FIFO-only fields when the queue URL is not a .fifo queue', function () {
    Log::spy();
    $captured = null;

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldReceive('sendMessage')
        ->once()
        ->andReturnUsing(function ($args) use (&$captured) {
            $captured = $args;

            return new Result(['MessageId' => 'msg-1']);
        });

    $config = makeSqsConfig();
    $config['sqs']['queue_url'] = 'https://sqs.us-east-1.amazonaws.com/123/standard-queue';

    $channel = new ServiceBusSQSChannel($config, $sqsMock);
    $channel->send(new AnonymousNotifiable(), new TestNotification());

    expect($captured)->not->toHaveKey('MessageGroupId');
    expect($captured)->not->toHaveKey('MessageDeduplicationId');
    expect($captured['QueueUrl'])->toEqual('https://sqs.us-east-1.amazonaws.com/123/standard-queue');
    expect(json_decode($captured['MessageBody'], true))->not->toBeNull();
});

it('retries once on AWS credential error and succeeds on the second attempt', function () {
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
});

it('retries on each credential error code in the allowlist', function (string $code) {
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
})->with([
    'ExpiredToken',
    'ExpiredTokenException',
    'InvalidClientTokenId',
    'UnrecognizedClientException',
    'RequestExpired',
    'TokenRefreshRequired',
]);

it('throws the original AwsException after exhausting credential retries', function () {
    Log::spy();
    $credentialError = makeAwsCredentialError('ExpiredToken');

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldReceive('sendMessage')
        ->twice()
        ->andThrow($credentialError);

    $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);

    expect(fn () => $channel->send(new AnonymousNotifiable(), new TestNotification()))
        ->toThrow(AwsException::class, 'AWS error: ExpiredToken');
});

it('does not retry on non-credential AWS errors', function () {
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
});

it('does not log success message when event type is in dont_report', function () {
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
});
