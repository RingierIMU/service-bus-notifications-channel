<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Ringierimu\ServiceBusNotificationsChannel\ServiceBusServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceBusServiceProvider::class];
    }
}
