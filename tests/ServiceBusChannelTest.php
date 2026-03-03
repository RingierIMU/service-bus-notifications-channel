<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use Ringierimu\ServiceBusNotificationsChannel\Tests\TestNotification;

function makeV2Config(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'node_id' => '123456789',
        'username' => 'user',
        'password' => 'pass',
        'version' => '2.0.0',
        'endpoint' => 'https://bus.example.com/v1/',
        'dont_report' => [],
    ], $overrides);
}

function makeV1Config(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'venture_config_id' => '123456789',
        'username' => 'user',
        'password' => 'pass',
        'version' => '1.0.0',
        'culture' => 'en_GB',
        'endpoint' => 'https://bus.example.com/v1/',
        'dont_report' => [],
    ], $overrides);
}

function makeClient(array $responses): Client
{
    $mock = new MockHandler($responses);

    return new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://bus.example.com/v1/']);
}

// Test 1 (COVR-06): Disabled channel logs debug
it('logs debug when disabled and event not in dont_report', function () {
    Log::shouldReceive('debug')
        ->once()
        ->with(
            \Mockery::pattern('/test.*\[disabled\]/'),
            \Mockery::type('array'),
        );

    $client = makeClient([]);
    $channel = new ServiceBusChannel(makeV2Config(['enabled' => false]), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());
});

// Test 2 (COVR-06): Disabled channel with dont_report suppresses log
it('suppresses log when disabled and event in dont_report', function () {
    Log::shouldReceive('debug')->never();

    $client = makeClient([]);
    $channel = new ServiceBusChannel(
        makeV2Config(['enabled' => false, 'dont_report' => ['test']]),
        $client,
    );

    $channel->send(new AnonymousNotifiable(), new TestNotification());
});

// Test 3 (baseline): Happy path v2
it('sends event successfully with v2 config', function () {
    Log::spy();

    $client = makeClient([
        new Response(200, [], json_encode(['token' => 'test-token', 'code' => 200])),
        new Response(200, [], 'OK'),
    ]);

    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());

    Log::shouldHaveReceived('info')->once();
});

// Test 4 (baseline): Happy path v1
it('sends event successfully with v1 config', function () {
    Log::spy();

    $client = makeClient([
        new Response(200, [], json_encode(['token' => 'test-token', 'code' => 200])),
        new Response(200, [], 'OK'),
    ]);

    $channel = new ServiceBusChannel(makeV1Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());

    Log::shouldHaveReceived('info')->once();
});
