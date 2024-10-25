<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use stdClass;
use Throwable;

/**
 * Class ServiceBusChannelTest.
 */
class ServiceBusChannelTest extends TestCase
{
    /**
     * @throws GuzzleException
     * @throws CouldNotSendNotification
     * @throws Throwable
     */
    public function testShouldThrowRequestExceptionOnSendEvent()
    {
        $this->expectException(CouldNotSendNotification::class);

        $serviceChannel = new ServiceBusChannel();

        $serviceChannel->send(
            new AnonymousNotifiable(),
            new TestNotification()
        );
    }
}
