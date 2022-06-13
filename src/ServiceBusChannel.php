<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
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
    protected $ventureConfig = [];

    /**
     * ServiceBusChannel constructor.
     *
     * @param array $ventureConfig
     */
    public function __construct(array $ventureConfig = [])
    {
        $this->ventureConfig = $ventureConfig ?: config('services.service_bus');

        $this->client = new Client(
            [
                'base_uri' => Arr::get($this->ventureConfig, 'endpoint'),
            ]
        );
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
        $dontReport = Arr::get($this->ventureConfig, 'dont_report', []);

        if (Arr::get($this->ventureConfig, 'enabled') == false) {
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

        $token = $this->getToken();

        $headers = [
            'Accept' => 'application/json',
            'Content-type' => 'application/json',
            'x-api-key' => $token,
        ];

        try {
            $this->client->request(
                'POST',
                $this->getUrl('events'),
                [
                    'headers' => $headers,
                    'json' => [$params],
                ]
            );

            if (!in_array($eventType, $dontReport)) {
                Log::debug(
                    "$eventType service bus notification",
                    [
                        'event' => $eventType,
                        'params' => $params,
                        'tags' => [
                            'service-bus',
                        ],
                    ]
                );
            }
        } catch (RequestException $exception) {
            $code = $exception->getCode();

            if (in_array($code, [401, 403])) {
                Log::info(
                    "$code received. Logging in and retrying.",
                    [
                        'event' => $eventType,
                        'params' => $params,
                        'tags' => [
                            'service-bus',
                        ],
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
        return Cache::tags('service-bus-token')
            ->rememberForever(
                $this->generateTokenKey(),
                function () {
                    try {
                        $response = $this->client->request(
                            'POST',
                            $this->getUrl('login'),
                            [
                                'json' => Arr::only($this->ventureConfig, ['username', 'password', 'venture_config_id']),
                            ]
                        );

                        $body = json_decode((string) $response->getBody(), true);

                        $code = (int) Arr::get($body, 'code', $response->getStatusCode());

                        switch ($code) {
                            case 200:
                                return $body['token'];
                            default:
                                throw CouldNotSendNotification::loginFailed($response);
                        }
                    } catch (RequestException $exception) {
                        throw CouldNotSendNotification::requestFailed($exception);
                    }
                }
            );
    }

    /**
     * @param string $endpoint
     *
     * @return string
     */
    private function getUrl(string $endpoint): string
    {
        return $endpoint;
    }

    public function generateTokenKey()
    {
        return md5(
            'service-bus-token-with-tag' .
            Arr::get($this->ventureConfig, 'venture_config_id')
        );
    }
}
