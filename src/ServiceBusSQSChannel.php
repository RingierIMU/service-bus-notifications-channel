<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ServiceBusSQSChannel
{
    /**
     * AWS error codes that indicate the SQS client's credentials are stale and
     * a single rebuild-and-retry is worth attempting before failing the send.
     */
    protected const CREDENTIAL_ERROR_CODES = [
        'ExpiredToken',
        'ExpiredTokenException',
        'InvalidClientTokenId',
        'UnrecognizedClientException',
        'RequestExpired',
        'TokenRefreshRequired',
    ];

    protected const MAX_SEND_ATTEMPTS = 2;

    protected array $config;

    protected SqsClient $sqs;

    /**
     * Retained so refresh-on-credential-error is a no-op when a client was
     * supplied by the caller (tests inject a mock and expect retry behavior
     * to be verified against the same mock instance).
     */
    protected ?SqsClient $injectedSqs;

    public function __construct(array $config = [], ?SqsClient $sqs = null)
    {
        $this->config = $config ?: config('services.service_bus');
        $this->injectedSqs = $sqs;
        $this->sqs = $sqs ?? $this->buildSqsClient();
    }

    public function send($notifiable, Notification $notification): void
    {
        /** @var ServiceBusEvent $event */
        $event = $notification->toServiceBus($notifiable);
        $eventType = $event->getEventType();
        $params = $event->getParams();
        $dontReport = Arr::get($this->config, 'dont_report', []);

        if (Arr::get($this->config, 'enabled') == false) {
            if (!in_array($eventType, $dontReport)) {
                Log::debug(
                    "$eventType service bus notification [disabled]",
                    [
                        'event' => $eventType,
                        'params' => $params,
                        'tags' => ['service-bus'],
                    ],
                );
            }

            return;
        }

        $payload = $this->buildSqsPayload($params);

        $response = $this->sendWithCredentialRetry($payload, $eventType, $params);

        if (!in_array($eventType, $dontReport)) {
            Log::info(
                "{$eventType} sent to bus queue",
                [
                    'message_id' => $response->get('MessageId'),
                    'message' => $params,
                ],
            );
        }
    }

    protected function buildSqsPayload(array $params): array
    {
        $queueUrl = Arr::get($this->config, 'sqs.queue_url');
        $body = json_encode($params);

        $payload = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $body,
        ];

        if ($this->isFifoQueue($queueUrl)) {
            $payload['MessageGroupId'] = $this->getMessageGroupId($params);
            // Explicit MessageDeduplicationId so dedup works regardless of
            // whether ContentBasedDeduplication is enabled on the queue.
            $payload['MessageDeduplicationId'] = md5($body);
        }

        return $payload;
    }

    protected function isFifoQueue(?string $queueUrl): bool
    {
        return is_string($queueUrl) && str_ends_with($queueUrl, '.fifo');
    }

    protected function sendWithCredentialRetry(array $payload, string $eventType, array $params): Result
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_SEND_ATTEMPTS; $attempt++) {
            try {
                return $this->sqs->sendMessage($payload);
            } catch (AwsException $exception) {
                $code = $exception->getAwsErrorCode();

                if (!in_array($code, self::CREDENTIAL_ERROR_CODES, true)) {
                    throw $exception;
                }

                $lastException = $exception;

                if ($attempt >= self::MAX_SEND_ATTEMPTS) {
                    break;
                }

                Log::warning(
                    "$code received from SQS — refreshing client and retrying",
                    [
                        'event' => $eventType,
                        'params' => $params,
                        'attempt' => $attempt,
                        'aws_error_code' => $code,
                        'aws_error_message' => $exception->getAwsErrorMessage(),
                        'tags' => ['service-bus'],
                    ],
                );

                $this->refreshSqsClient();
            }
        }

        throw $lastException;
    }

    protected function getMessageGroupId(array $message): string
    {
        $messageGroupId = $message['from'];

        // Each payload-keyed arm uses Arr::get with dot notation so a missing
        // nested key returns null (whole arm = null → falls through to the
        // ternary below and we return bare `from`). Avoids the operator-
        // precedence trap of `'x=' . $arr['k'] ?? null`, where `.` binds
        // tighter than `??` and the array access still raises a warning.
        $append = match ($message['events'][0] ?? null) {
            'ListingCreated',
            'ListingUpdated',
            'ListingDeleted',
            'ListingLeadCreated',
            'ListingPromoted',
            'ListingProductsAdded',
            'ListingProductsRemoved',
            'ListingShared',
            'ListingFavouriteCreated',
            'ListingFavouriteRemoved' => ($ref = Arr::get($message, 'payload.listing.reference')) ? "listing=$ref" : null,

            'TopicCreated',
            'TopicUpdated',
            'TopicDeleted' => ($ref = Arr::get($message, 'payload.topic.reference')) ? "topic=$ref" : null,

            'AlertCreated',
            'AlertUpdated',
            'AlertDeleted',
            'AlertSent' => ($ref = Arr::get($message, 'payload.alert.user.reference')) ? "user_alert=$ref" : null,

            'AdvertiserCreated',
            'AdvertiserUpdated',
            'AdvertiserDeleted',
            'AdvertiserProductsAdded',
            'AdvertiserProductsRemoved',
            'AdvertiserLeadCreated' => ($ref = Arr::get($message, 'payload.advertiser.reference')) ? "advertiser=$ref" : null,

            'UserCreated',
            'UserUpdated',
            'UserDeleted',
            'UserLogin',
            'UserLogout',
            'UserPasswordRequest',
            'UserPasswordReset',
            'UserVerified',
            'UserProductsAdded',
            'UserProductsRemoved',
            'UserLeadCreated',
            'UserAnonymized' => ($ref = Arr::get($message, 'payload.user.reference')) ? "user=$ref" : null,

            'SiteLeadCreated' => 'site_lead',

            'Callback',
            'Error' => 'callback',

            'TestRunsDispatched',
            'TestRunReceived',
            'TestRunStarted',
            'TestRunComplete' => 'test_run',

            'ArticleCreated',
            'ArticleUpdated',
            'ArticleDeleted' => ($ref = Arr::get($message, 'payload.article.reference')) ? "article=$ref" : null,

            'AuthorCreated',
            'AuthorUpdated',
            'AuthorDeleted' => ($ref = Arr::get($message, 'payload.author.reference')) ? "author=$ref" : null,

            'SportEventCreated',
            'SportEventUpdated',
            'SportEventDeleted' => ($ref = Arr::get($message, 'payload.sport_event.reference')) ? "sport_event=$ref" : null,

            'NewsletterSubscribed',
            'NewsletterUnsubscribed' => 'newsletter',

            default => null,
        };

        return $append ? $messageGroupId . '_' . $append : $messageGroupId;
    }

    protected function buildSqsClient(): SqsClient
    {
        return new SqsClient([
            'region' => Arr::get($this->config, 'sqs.region'),
            'version' => 'latest',
            'credentials' => [
                'key' => Arr::get($this->config, 'sqs.key'),
                'secret' => Arr::get($this->config, 'sqs.secret'),
            ],
        ]);
    }

    protected function refreshSqsClient(): void
    {
        if ($this->injectedSqs !== null) {
            return;
        }

        $this->sqs = $this->buildSqsClient();
    }
}
