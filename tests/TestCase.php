<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Tests;

use Illuminate\Config\Repository;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [ServiceBusServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        tap($app["config"], function (Repository $config) {
            $config->set("services.service_bus", [
                "enabled" => true,
                "node_id" => "123456789",
                "username" => "username",
                "password" => "password",
                "version" => "2.0.0",
                "endpoint" => "https://bus.staging.ritdu.tech/v1/",
            ]);
        });
    }
}
