<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Throwable;

/**
 * Class ServiceBusChannel.
 */
class ServiceBusChannel
{
    /**
     * @var Client
     */
    private $client;
    protected $hasAttemptedLogin = false;
    protected $useStaging = false;
    protected $ventureConfig = [];

    /**
     * Send the given notification.
     *
     * @param mixed        $notifiable
     * @param Notification $notification
     *
     * @throws CouldNotSendNotification
     * @throws GuzzleException
     * @throws Throwable
     */
    public function send($notifiable, Notification $notification)
    {
        $this->setVentureConfig($notification);

        /** @var ServiceBusEvent $event */
        $event = $notification->toServiceBus($notifiable);

        $params = $event->getParams();

        if ($this->ventureConfig['services.service_bus.enabled'] == false) {
            Log::info('Service Bus disabled, event discarded', ['tag' => 'ServiceBus']);
            Log::debug(print_r($params, true), ['tag' => 'ServiceBus']);

            return;
        }

        $this->client = new Client([
            'base_uri' => $this->ventureConfig['services.service_bus.endpoint'],
        ]);

        $token = $this->getToken();

        $headers = [
            'Accept'        => 'application/json',
            'Content-type'  => 'application/json',
            'x-api-key'     => $token,
        ];

        try {
            $this->client->request('POST',
                $this->getUrl('events'),
                [
                    'headers'   => $headers,
                    'json'      => [$params],
                ]
            );

            Log::info('Notification sent', ['tag' => 'ServiceBus', 'event' => $event->getEventType()]);
        } catch (RequestException $exception) {
            if ($exception->getCode() == '403') {
                Log::info('403 received. Logging in and retrying', ['tag' => 'ServiceBus']);

                // clear the invalid token //
                Cache::forget($this->generateTokenKey());

                if (!$this->hasAttemptedLogin) {
                    // redo the call which will now redo the login //
                    $this->hasAttemptedLogin = true;
                    $this->send($notifiable, $notification);
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
     * @throws CouldNotSendNotification
     * @throws GuzzleException
     *
     * @return string
     */
    private function getToken(): string
    {
        $token = Cache::get($this->generateTokenKey());

        if (empty($token)) {
            try {
                $body = $this->client->request('POST',
                    $this->getUrl('login'),
                    [
                        'json' => [
                            'username'          => $this->ventureConfig['services.service_bus.username'],
                            'password'          => $this->ventureConfig['services.service_bus.password'],
                            'venture_config_id' => $this->ventureConfig['services.service_bus.venture_config_id'],
                        ],
                    ]
                )->getBody();

                $json = json_decode($body);

                $token = $json->token;

                // there is no timeout on tokens, so cache it forever //
                Cache::forever($this->generateTokenKey(), $token);

                Log::info('Token received', ['tag' => 'ServiceBus']);
            } catch (RequestException $exception) {
                throw CouldNotSendNotification::requestFailed($exception);
            }
        }

        return $token;
    }

    /**
     * @param $endpoint
     *
     * @return string
     */
    private function getUrl($endpoint): string
    {
        return $endpoint;
    }

    public function generateTokenKey()
    {
        if (empty($ventureConfig)) {
            $this->setVentureConfig();
        }

        return md5(
            'service-bus-token'.
            $this->ventureConfig['services.service_bus.venture_config_id']
        );
    }

    /**
     * @param Notification $notification
     */
    private function setVentureConfig($notification = null)
    {
        $ventureConfigVars = [
            'services.service_bus.username',
            'services.service_bus.password',
            'services.service_bus.venture_config_id',
            'services.service_bus.enabled',
            'services.service_bus.endpoint',
        ];

        foreach ($ventureConfigVars as $name) {
            $this->ventureConfig[$name] = isset($notification->config) && isset($notification->config[$name])
                ? $notification->config[$name] : config($name);
        }
    }
}
