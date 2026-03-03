<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use Ringierimu\ServiceBusNotificationsChannel\Tests\TestNotification;

it('should throw request exception on send event', function () {
    $serviceChannel = new ServiceBusChannel();

    $serviceChannel->send(
        new AnonymousNotifiable(),
        new TestNotification()
    );
})->throws(CouldNotSendNotification::class);
