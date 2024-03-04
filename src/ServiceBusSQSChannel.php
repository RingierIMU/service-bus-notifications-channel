<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use Aws\Sqs\SqsClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ServiceBusSQSChannel
{
    protected SqsClient $sqs;

    public function __construct(array $config = [])
    {
        $this->config = $config ?: config('services.service_bus');

        $this->sqs = new SqsClient([
            'region' => Arr::get($this->config, 'sqs.region'),
            'version' => 'latest',
            'credentials' => [
                'key' => Arr::get($this->config, 'sqs.key'),
                'secret' => Arr::get($this->config, 'sqs.secret'),
            ],
        ]);
    }

    public function send($notifiable, Notification $notification)
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
                        'tags' => [
                            'service-bus',
                        ],
                    ]
                );
            }

            return;
        }

        $message = $notification
            ->toServiceBus($notifiable)
            ->getParams();

        $response = $this->sqs->sendMessage([
            'QueueUrl' => Arr::get($this->config, 'sqs.queue_url'),
            'MessageBody' => json_encode($message),
            'MessageGroupId' => $message['from'],
        ]);

        $event = $message['events'][0];

        Log::info("{$event} sent to bus queue", [
            'message_id' => $response->get('MessageId'),
            'message' => $message,
        ]);
    }
}
