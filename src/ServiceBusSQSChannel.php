<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ServiceBusSQSChannel
{
    protected SqsClient $sqs;

    protected array $config;

    protected bool $hasAttemptedRefresh = false;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: config('services.service_bus');
        $this->initializeSqsClient();
    }

    protected function initializeSqsClient(): void
    {
        $this->sqs = new SqsClient([
            'region' => Arr::get($this->config, 'sqs.region'),
            'version' => 'latest',
            'credentials' => [
                'key' => Arr::get($this->config, 'sqs.key'),
                'secret' => Arr::get($this->config, 'sqs.secret'),
            ],
        ]);
    }

    public function send($notifiable, Notification $notification): void
    {
        /** @var ServiceBusEvent $event */
        $event = $notification->toServiceBus($notifiable);
        $eventType = $event->getEventType();
        $message = $event->getParams();
        $dontReport = Arr::get($this->config, 'dont_report', []);

        if (Arr::get($this->config, 'enabled') == false) {
            if (!in_array($eventType, $dontReport)) {
                Log::debug(
                    "$eventType service bus notification [disabled]",
                    [
                        'event' => $eventType,
                        'params' => $message,
                        'tags' => [
                            'service-bus',
                        ],
                    ]
                );
            }

            return;
        }

        if (!isset($message['from'], $message['events'][0])) {
            Log::error('Invalid message structure', ['message' => $message]);
            return;
        }

        $queueUrl = Arr::get($this->config, 'sqs.queue_url');
        $isFifoQueue = strpos($queueUrl, '.fifo') !== false;

        $params = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => json_encode($message),
        ];

        if ($isFifoQueue) {
            $params['MessageGroupId'] = $message['from'];
            $params['MessageDeduplicationId'] = md5(json_encode($message));
        }

        try {
            $response = $this->sqs->sendMessage($params);

            $eventName = $message['events'][0];

            Log::info("{$eventName} sent to bus queue", [
                'message_id' => $response->get('MessageId'),
                'event' => $eventName,
            ]);

            $this->hasAttemptedRefresh = false;
        } catch (AwsException $exception) {
            $code = $exception->getAwsErrorCode();

            if (in_array($code, ['ExpiredToken', 'UnrecognizedClientException', 'InvalidClientTokenId'])) {
                Log::info("$code received. Refreshing credentials and retrying.", [
                    'event' => $eventType,
                    'params' => $message,
                    'aws_error_code' => $code,
                    'aws_error_message' => $exception->getAwsErrorMessage(),
                    'tags' => ['service-bus'],
                ]);

                if (!$this->hasAttemptedRefresh) {
                    $this->hasAttemptedRefresh = true;

                    $this->initializeSqsClient();

                    $this->send($notifiable, $notification);
                } else {
                    $this->hasAttemptedRefresh = false;

                    throw new \Exception('Authentication failed after retrying.', 0, $exception);
                }
            } else {
                throw $exception;
            }
        }
    }
}
