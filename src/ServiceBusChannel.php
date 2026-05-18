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

class ServiceBusChannel
{
    protected bool $hasAttemptedLogin = false;

    protected array $config = [];

    private readonly Client $client;

    public function __construct(array $config = [], Client|null $client = null)
    {
        $this->config = $config ?: config('services.service_bus');

        $this->client = $client ?? new Client(
            [
                'base_uri' => Arr::get($this->config, 'endpoint'),
            ],
        );
    }

    /**
     * @throws CouldNotSendNotification
     * @throws GuzzleException
     * @throws Throwable
     */
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

        $token = $this->getToken();

        $headers = [
            'Accept' => 'application/json',
            'Content-type' => 'application/json',
            'x-api-key' => $token,
        ];

        try {
            $response = $this->client->request(
                'POST',
                'events',
                [
                    'headers' => $headers,
                    'json' => [$params],
                ],
            );

            Log::info(
                "$eventType service bus notification",
                [
                    'event' => $eventType,
                    'params' => $params,
                    'tags' => ['service-bus'],
                    'status' => $response->getStatusCode(),
                ],
            );
        } catch (RequestException $exception) {
            $code = $exception->getCode();

            if (in_array($code, [401, 403])) {
                Log::info(
                    "$code received. Logging in and retrying.",
                    [
                        'event' => $eventType,
                        'params' => $params,
                        'tags' => ['service-bus'],
                    ],
                );

                Cache::forget($this->generateTokenKey());

                if (!$this->hasAttemptedLogin) {
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
     */
    protected function getToken(): string
    {
        return Cache::rememberForever(
            $this->generateTokenKey(),
            function () {
                try {
                    $response = $this->client->request(
                        'POST',
                        'login',
                        [
                            'json' => Arr::only(
                                $this->config,
                                [
                                    'username',
                                    'password',
                                    $this->tenantConfigKey(),
                                ],
                            ),
                        ],
                    );

                    $body = json_decode((string) $response->getBody(), true);

                    $code = (int) Arr::get(
                        $body,
                        'code',
                        $response->getStatusCode(),
                    );

                    return match ($code) {
                        200 => $body['token'],
                        default => throw CouldNotSendNotification::loginFailed($response),
                    };
                } catch (RequestException $exception) {
                    throw CouldNotSendNotification::requestFailed($exception);
                }
            },
        );
    }

    protected function generateTokenKey(): string
    {
        return md5('service-bus-token' . Arr::get($this->config, $this->tenantConfigKey()));
    }

    protected function tenantConfigKey(): string
    {
        return ((int) $this->config['version']) < 2 ? 'venture_config_id' : 'node_id';
    }
}
