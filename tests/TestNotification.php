<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;

/**
 * Class TestNotification.
 */
class TestNotification extends Notification
{
    /**
     * @throws InvalidConfigException
     *
     * @return ServiceBusEvent
     */
    public function toServiceBus()
    {
        return ServiceBusEvent::create("test")
            ->withAction("other", uniqid())
            ->withCulture("en")
            ->withReference(uniqid())
            ->withRoute("api")
            ->createdAt(Carbon::now())
            ->withResources("resources", ["data"]);
    }
}
