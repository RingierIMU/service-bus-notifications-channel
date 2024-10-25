<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use Throwable;

/**
 * Class ServiceBusChannelTest.
 */
class ServiceBusChannelTest extends TestCase
{
    public function testShouldCreateServiceBusChannelInstance()
    {
        $this->mockAll();
        $serviceChannel = new ServiceBusChannel(config_v2());

        $this->assertNotNull($serviceChannel);
    }

    /**
     * @throws CouldNotSendNotification
     * @throws Throwable
     */
    public function testShouldThrowRequestExceptionOnSendEvent()
    {
        $this->expectException(CouldNotSendNotification::class);

        $this->mockAll();
        Cache::shouldReceive("rememberForever")->andReturn(true);
        Cache::shouldReceive("forget")->andReturn(true);

        $serviceChannel = new ServiceBusChannel();

        $serviceChannel->send(
            new AnonymousNotifiable(),
            new TestNotification()
        );
    }

    /**
     * Mock classes, facades and everything else needed.
     */
    private function mockAll()
    {
        Cache::shouldReceive("get")
            ->once()
            ->with((new ServiceBusChannel(config_v2()))->generateTokenKey())
            ->andReturn("value");

        Log::shouldReceive("debug")->once()->andReturnNull();

        Log::shouldReceive("info")->once()->andReturnNull();

        Log::shouldReceive("error")->once()->andReturnNull();
    }
}
