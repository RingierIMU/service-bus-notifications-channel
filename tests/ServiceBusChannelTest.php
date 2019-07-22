<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use stdClass;
use Throwable;

/**
 * Class ServiceBusChannelTest.
 */
class ServiceBusChannelTest extends TestCase
{
    public function testShouldCreateServiceBusChannelInstance()
    {
        $this->mockAll();
        $serviceChannel = new ServiceBusChannel();

        $this->assertNotNull($serviceChannel);
    }

    /**
     * @throws GuzzleException
     * @throws CouldNotSendNotification
     * @throws Throwable
     */
    public function testShouldThrowRequestExceptionOnSendEvent()
    {
        $this->expectException(CouldNotSendNotification::class);

        $this->mockAll();
        $serviceChannel = new ServiceBusChannel();

        $serviceChannel->send(new AnonymousNotifiable(), new TestNotification());
    }
    
    /**
     * Mock classes, facades and everything else needed.
     */
    private function mockAll()
    {
        Cache::shouldReceive('get')
            ->once()
            ->with(ServiceBusChannel::CACHE_KEY_TOKEN)
            ->andReturn('value');

        Log::shouldReceive('debug')
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->once()
            ->andReturnNull();

        Log::shouldReceive('error')
            ->once()
            ->andReturnNull();

        Mockery::mock(Client::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')
                ->andReturn(new stdClass())
                ->once();
        });
    }
}
