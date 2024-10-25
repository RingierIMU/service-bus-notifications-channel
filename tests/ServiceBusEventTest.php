<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Illuminate\Support\Carbon;
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
        $serviceBus = new ServiceBusEvent("test", config_v2());

        $this->assertEquals("test", $serviceBus->getEventType());
    }

    public function testShouldCreateServiceBusEventInstanceViaStaticCall()
    {
        $serviceBus = ServiceBusEvent::create("test", config_v2());

        $this->assertEquals("test", $serviceBus->getEventType());
    }

    /**
     * @throws InvalidConfigException
     */
    public function testShouldThrowInvalidConfigException()
    {
        $this->expectException(InvalidConfigException::class);

        ServiceBusEvent::create("test")
            ->withAction("test", uniqid())
            ->withCulture("en")
            ->withReference(uniqid())
            ->withRoute("api")
            ->createdAt(Carbon::now())
            ->withResources("resources", ["data"]);
    }

    /**
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function testShouldAllocateAttributesToServiceBusObject()
    {
        $resource = [
            "user" => "John Doe",
            "email" => "john@doe.com",
            "phone" => "0123456789",
        ];

        $serviceBus = ServiceBusEvent::create("test", config_v2())
            ->withAction("other", uniqid())
            ->withCulture("en")
            ->withReference(uniqid())
            ->withRoute("api")
            ->createdAt(Carbon::now())
            ->withResources("resource", $resource);

        $serviceBusData = $serviceBus->getParams();

        $this->assertNotEmpty($serviceBusData);
        $this->assertArrayHasKey("events", $serviceBusData);
        $this->assertArrayHasKey("payload", $serviceBusData);
        $this->assertArrayHasKey("resource", $serviceBusData["payload"]);
        $this->assertContains("test", $serviceBusData["events"]);

        $this->assertEquals($resource, $serviceBusData["payload"]["resource"]);
    }

    /**
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function testShouldAllocateAttributesToServiceBusObjectWithPayload()
    {
        $payload = [
            "object" => [
                "user" => "John Doe",
                "email" => "john@doe.com",
                "phone" => "0123456789",
            ],
        ];

        $serviceBus = ServiceBusEvent::create("test", config_v2())
            ->withAction("other", uniqid())
            ->withCulture("en")
            ->withReference(uniqid())
            ->withRoute("api")
            ->createdAt(Carbon::now())
            ->withPayload($payload);

        $serviceBusData = $serviceBus->getParams();

        $this->assertNotEmpty($serviceBusData);
        $this->assertArrayHasKey("events", $serviceBusData);
        $this->assertArrayHasKey("payload", $serviceBusData);
        $this->assertContains("test", $serviceBusData["events"]);

        $this->assertEquals($payload, $serviceBusData["payload"]);
    }

    /**
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function testShouldReturnCorrectEventForSpecificVersion()
    {
        $serviceBusVersion1 = ServiceBusEvent::create("test", config_v1())
            ->withAction("other", uniqid())
            ->withCulture("en")
            ->withReference(uniqid())
            ->withRoute("api")
            ->withPayload([
                "listing" => [],
            ])
            ->createdAt(Carbon::now());

        $serviceBusVersion2 = ServiceBusEvent::create("test", config_v2())
            ->withReference(uniqid())
            ->withRoute("api")
            ->withPayload([
                "listing" => [],
            ])
            ->createdAt(Carbon::now());

        $serviceBusDataVersion1 = $serviceBusVersion1->getParams();
        $serviceBusDataVersion2 = $serviceBusVersion2->getParams();

        $this->assertNotEmpty($serviceBusDataVersion1);
        $this->assertNotEmpty($serviceBusDataVersion2);

        $this->assertArrayHasKey("events", $serviceBusDataVersion1);
        $this->assertArrayHasKey("payload", $serviceBusDataVersion1);
        $this->assertArrayHasKey("from", $serviceBusDataVersion1);
        $this->assertArrayHasKey("venture_config_id", $serviceBusDataVersion1);
        $this->assertArrayHasKey("venture_reference", $serviceBusDataVersion1);
        $this->assertArrayHasKey("reference", $serviceBusDataVersion1);

        $this->assertArrayHasKey("from", $serviceBusDataVersion2);
        $this->assertArrayHasKey("events", $serviceBusDataVersion2);
        $this->assertArrayHasKey("payload", $serviceBusDataVersion2);
        $this->assertArrayHasKey("reference", $serviceBusDataVersion2);

        $this->assertNotEmpty($serviceBusDataVersion1["venture_config_id"]);
        $this->assertNotEmpty($serviceBusDataVersion1["venture_reference"]);

        $this->assertNotEmpty($serviceBusDataVersion2["from"]);
        $this->assertNotEmpty($serviceBusDataVersion2["reference"]);

        $this->assertContains("test", $serviceBusDataVersion1["events"]);
        $this->assertContains("test", $serviceBusDataVersion2["events"]);
    }
}
