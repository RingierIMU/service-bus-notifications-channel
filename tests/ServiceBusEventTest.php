<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;
use Throwable;

/**
 * Class ServiceBusEventTest.
 */
class ServiceBusEventTest extends TestCase
{
    public function testShouldCreateServiceBusEventInstance()
    {
        $serviceBus = new ServiceBusEvent('test');

        $this->assertEquals('test', $serviceBus->getEventType());
    }

    public function testShouldCreateServiceBusEventInstanceViaStaticCall()
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
        $resource = [
            'user' => 'John Doe',
            'email' => 'john@doe.com',
            'phone' => '0123456789',
        ];

        $serviceBus = ServiceBusEvent::create('test')
            ->withAction('other', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withResources('resource', $resource);

        $serviceBusData = $serviceBus->getParams();

        $this->assertNotEmpty($serviceBusData);
        $this->assertArrayHasKey('events', $serviceBusData);
        $this->assertArrayHasKey('payload', $serviceBusData);
        $this->assertArrayHasKey('resource', $serviceBusData['payload']);
        $this->assertContains('test', $serviceBusData['events']);

        $this->assertEquals([$resource], $serviceBusData['payload']['resource']);
    }

    /**
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function testShouldAllocateAttributesToServiceBusObjectWithPayload()
    {
        $payload = [
            'object' => [
                'user' => 'John Doe',
                'email' => 'john@doe.com',
                'phone' => '0123456789',
            ],
        ];

        $serviceBus = ServiceBusEvent::create('test')
            ->withAction('other', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload($payload);

        $serviceBusData = $serviceBus->getParams();

        $this->assertNotEmpty($serviceBusData);
        $this->assertArrayHasKey('events', $serviceBusData);
        $this->assertArrayHasKey('payload', $serviceBusData);
        $this->assertContains('test', $serviceBusData['events']);

        $this->assertEquals($payload, $serviceBusData['payload']);
    }
}
