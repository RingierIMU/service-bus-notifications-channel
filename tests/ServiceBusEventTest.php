<?php

namespace NotificationChannels\ServiceBusNotificationsChannel\Tests;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;
use Throwable;

/**
 * Class ServiceBusEventTest.
 */
class ServiceBusEventTest extends TestCase
{
    public function testShouldCreateServiceBusChannelInstance()
    {
        $serviceBus = new ServiceBusEvent('test');

        $this->assertEquals('test', $serviceBus->getEventType());
    }

    public function testShouldCreateServiceBusChannelInstanceViaStaticCall()
    {
        $serviceBus = ServiceBusEvent::create('test');

        $this->assertEquals('test', $serviceBus->getEventType());
    }

    /**
     * @throws InvalidConfigException
     */
    public function testShouldThrowInvalidConfigException()
    {
        $this->expectException(InvalidConfigException::class);

        ServiceBusEvent::create('test')
            ->withAction('test', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withResources('resources', ['data']);
    }

    /**
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function testShouldAllocateAttributesToServiceBusObject()
    {
        $serviceBus = ServiceBusEvent::create('test')
            ->withAction('other', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withResources('resources', ['data']);

        $serviceBusData = $serviceBus->getParams();

        $this->assertNotEmpty($serviceBusData);
        $this->assertArrayHasKey('events', $serviceBusData);
        $this->assertArrayHasKey('payload', $serviceBusData);
        $this->assertArrayHasKey('resources', $serviceBusData['payload']);
        $this->assertContains('test', $serviceBusData['events']);

        $this->assertEquals([['data']], $serviceBusData['payload']['resources']);
    }
}
