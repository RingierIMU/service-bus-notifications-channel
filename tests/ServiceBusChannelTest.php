<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use Ringierimu\ServiceBusNotificationsChannel\Tests\TestNotification;

it('should throw request exception on send event', function () {
    $config = [
        'enabled' => true,
        'node_id' => '123456789',
        'username' => 'username',
        'password' => 'password',
        'version' => '2.0.0',
        'endpoint' => 'https://bus.staging.ritdu.tech/v1/',
    ];

    $serviceChannel = new ServiceBusChannel($config);

    $serviceChannel->send(
        new AnonymousNotifiable(),
        new TestNotification()
    );
})->throws(CouldNotSendNotification::class);
