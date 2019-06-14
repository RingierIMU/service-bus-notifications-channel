<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Notification;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;

class ServiceBusChannel
{
    /**
     * @var Client
     */
    private $client;
    protected $hasAttemptedLogin = false;

    const CACHE_KEY_TOKEN = 'service-bus-token';

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://8504a7itki.execute-api.eu-west-1.amazonaws.com/staging'
        ]);
    }

    /**
     * Send the given notification.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     *
     * @throws \NotificationChannels\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification
     */
    public function send($notifiable, Notification $notification)
    {
        $token = $this->getToken();

        /** @var ServiceBusEvent $event */
        $event = $notification->toServiceBus($notifiable);

        $headers = [
            'x-api-key' => $token
        ];

        $response = $this->client->post(
            '/events',
            array(
                'headers' => $headers,
                'form_params' => $event->getParams()
            )
        );

        // todo: double check that this isn't a singleton and the hasAttemptedLogin var is reset //
        if($response->getStatusCode() == 403){
            // clear the token //
            Cache::forget(ServiceBusChannel::CACHE_KEY_TOKEN);
            if(!$this->hasAttemptedLogin) {
                // redo the call which will now redo the login //
                $this->send($notifiable, $notification);
                $this->hasAttemptedLogin = true;
            } else {
                $this->hasAttemptedLogin = false;
                throw CouldNotSendNotification::authFailed($response);
            }
        } else {
            Log::info('Event sent', ['ServiceBus']);
        }
    }

    private function getToken(): String{
        $token = Cache::get(ServiceBusChannel::CACHE_KEY_TOKEN);

        if(empty($token)){
            $body = $this->client->post(
                '/login',
                array(
                    'form_params' => array(
                        'username' => config('services.service_bus.username'),
                        'password' => config('services.service_bus.password'),
                        'venture_config_id' => config('services.service_bus.venture_config_id')
                    )
                )
            )->getBody();

            $json = json_decode($body);

            $token = $json->token;
            // there is no timeout on tokens, so cache it forever //
            Cache::forever('service-bus-token', $token);
        }

        return $token;
    }
}
