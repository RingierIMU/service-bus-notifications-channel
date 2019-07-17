<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Notification;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Stringy\StaticStringy;

/**
 * Class ServiceBusChannel
 * @package Ringierimu\ServiceBusNotificationsChannel
 */
class ServiceBusChannel
{
    /**
     * @var Client
     */
    private $client;
    protected $hasAttemptedLogin = false;
    protected $useStaging = false;

    const CACHE_KEY_TOKEN = 'service-bus-token';

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => config('services.service_bus.endpoint'),
        ]);
    }

    /**
     * Send the given notification.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     *
     * @throws \Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification
     */
    public function send($notifiable, Notification $notification)
    {
        /** @var ServiceBusEvent $event */
        $event = $notification->toServiceBus($notifiable);

        if (config('services.service_bus.enabled') == false) {
            Log::info('Service Bus disabled, event discarded', ['tag' => 'ServiceBus']);
            Log::debug(print_r($event->getParams(), true), ['tag' => 'ServiceBus']);
            return;
        }

        $token = $this->getToken();

        $headers = [
            'x-api-key' => $token
        ];

        try {
            $response = $this->client->post(
                $this->getUrl('events'),
                array(
                    'headers' => $headers,
                    'form_params' => $event->getParams()
                )
            );

            Log::info('Notification sent', ['tag' => 'ServiceBus', 'event' => $event->getEventType()]);
        } catch (RequestException $exception) {
            if ($exception->getCode() == '403') {
                Log::info('403 received. Logging in and retrying', ['tag' => 'ServiceBus']);

                // clear the invalid token //
                Cache::forget(ServiceBusChannel::CACHE_KEY_TOKEN);

                if (!$this->hasAttemptedLogin) {
                    // redo the call which will now redo the login //
                    $this->send($notifiable, $notification);
                    $this->hasAttemptedLogin = true;
                } else {
                    $this->hasAttemptedLogin = false;
                    throw CouldNotSendNotification::authFailed($exception);
                }
            } else {
                throw CouldNotSendNotification::requestFailed($exception);
            }
        }
    }

    /**
     * @return String
     * @throws CouldNotSendNotification
     */
    private function getToken(): String
    {
        $token = Cache::get(ServiceBusChannel::CACHE_KEY_TOKEN);

        if (empty($token)) {
            try {
                $body = $this->client->request('POST',
                    $this->getUrl('login'),
                    [
                        'json' => [
                            'username' => config('services.service_bus.username'),
                            'password' => config('services.service_bus.password'),
                            'venture_config_id' => config('services.service_bus.venture_config_id')
                        ]
                    ]
                )->getBody();

                $json = json_decode($body);

                $token = $json->token;

                // there is no timeout on tokens, so cache it forever //
                Cache::forever('service-bus-token', $token);

                Log::info('Token received', ['tag' => 'ServiceBus']);
            } catch (RequestException $exception) {
                throw CouldNotSendNotification::requestFailed($exception);
            }
        }

        return $token;
    }

    /**
     * @param $endpoint
     * @return String
     */
    private function getUrl($endpoint): String
    {
        return $endpoint;
    }
}
