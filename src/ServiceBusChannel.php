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
     * ServiceBusChannel constructor.
     *
     * @param array $ventureConfig
     */
    public function __construct(array $ventureConfig = [])
    {
        $ventureConfigVars = [
            'services.service_bus.username',
            'services.service_bus.password',
            'services.service_bus.venture_config_id',
            'services.service_bus.enabled',
            'services.service_bus.endpoint',
        ];

        foreach ($ventureConfigVars as $name) {
            $this->ventureConfig[$name] = isset($ventureConfig[$name]) ? $ventureConfig[$name] : config($name);
        }

        $this->client = new Client([
            'base_uri' => $this->ventureConfig['services.service_bus.endpoint'],
        ]);
    }

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
        /** @var ServiceBusEvent $event */
        $event = $notification->toServiceBus($notifiable);
        $eventType = $event->getEventType();
        $params = $event->getParams();

        if ($this->ventureConfig['services.service_bus.enabled'] == false) {
            Log::info(
                "$eventType service bus notification [disabled]",
                [
                    'event'  => $eventType,
                    'params' => $params,
                    'tag'    => 'ServiceBus',
                ]
            );

            return;
        }

        $token = $this->getToken();

        $headers = [
            'Accept'       => 'application/json',
            'Content-type' => 'application/json',
            'x-api-key'    => $token,
        ];

        try {
            $this->client->request(
                'POST',
                $this->getUrl('events'),
                [
                    'headers' => $headers,
                    'json'    => [$params],
                ]
            );

            Log::info(
                "$eventType service bus notification",
                [
                    'event'  => $eventType,
                    'params' => $params,
                    'tag'    => 'ServiceBus',
                ]
            );
        } catch (RequestException $exception) {
            if ($exception->getCode() == '403') {
                Log::info(
                    '403 received. Logging in and retrying',
                    [
                        'event'  => $eventType,
                        'params' => $params,
                        'tag'    => 'ServiceBus',
                    ]
                );

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
                $body = $this->client->request(
                    'POST',
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
        return md5(
            'service-bus-token'.
            $this->ventureConfig['services.service_bus.venture_config_id']
        );
    }
}
