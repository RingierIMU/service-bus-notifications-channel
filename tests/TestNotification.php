<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Carbon\Carbon;
use Illuminate\Notifications\Notification;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;

/**
 * Class TestNotification
 * @package Ringierimu\ServiceBusNotificationsChannel\Tests
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
        return ServiceBusEvent::create('test')
            ->withAction('other', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withResources('resources', ['data']);
    }
}