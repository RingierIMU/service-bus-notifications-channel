<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\CouldNotSendNotification;
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

// Test 5 (COVR-03): 401 retry success
it('retries after 401 with fresh token', function () {
    Log::spy();

    $loginResponse = new Response(200, [], json_encode(['token' => 'test-token', 'code' => 200]));
    $reLoginResponse = new Response(200, [], json_encode(['token' => 'fresh-token', 'code' => 200]));
    $eventsError = RequestException::create(
        new Request('POST', 'events'),
        new Response(401, [], 'Unauthorized'),
    );
    $eventsSuccess = new Response(200, [], 'OK');

    $client = makeClient([$loginResponse, $eventsError, $reLoginResponse, $eventsSuccess]);
    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());

    Log::shouldHaveReceived('info');
});

// Test 6 (COVR-03): 403 retry success
it('retries after 403 with fresh token', function () {
    Log::spy();

    $loginResponse = new Response(200, [], json_encode(['token' => 'test-token', 'code' => 200]));
    $reLoginResponse = new Response(200, [], json_encode(['token' => 'fresh-token', 'code' => 200]));
    $eventsError = RequestException::create(
        new Request('POST', 'events'),
        new Response(403, [], 'Forbidden'),
    );
    $eventsSuccess = new Response(200, [], 'OK');

    $client = makeClient([$loginResponse, $eventsError, $reLoginResponse, $eventsSuccess]);
    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());

    Log::shouldHaveReceived('info');
});

// Test 7 (COVR-03): Exhausted retry throws authFailed
it('throws authFailed after exhausted retry on double 401', function () {
    Log::spy();

    $loginResponse = new Response(200, [], json_encode(['token' => 'test-token', 'code' => 200]));
    $reLoginResponse = new Response(200, [], json_encode(['token' => 'fresh-token', 'code' => 200]));
    $eventsError1 = RequestException::create(
        new Request('POST', 'events'),
        new Response(401, [], 'Unauthorized'),
    );
    $eventsError2 = RequestException::create(
        new Request('POST', 'events'),
        new Response(401, [], 'Unauthorized'),
    );

    $client = makeClient([$loginResponse, $eventsError1, $reLoginResponse, $eventsError2]);
    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());
})->throws(CouldNotSendNotification::class, 'auth token');

// Test 8 (COVR-04): Non-auth request exception throws requestFailed
it('throws requestFailed on non-auth request exception', function () {
    Log::spy();

    $loginResponse = new Response(200, [], json_encode(['token' => 'test-token', 'code' => 200]));
    $eventsError = RequestException::create(
        new Request('POST', 'events'),
        new Response(500, [], 'Server Error'),
    );

    $client = makeClient([$loginResponse, $eventsError]);
    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());
})->throws(CouldNotSendNotification::class, 'logging the event');

// Test 9 (COVR-05): Login returns non-200 body code throws loginFailed
it('throws loginFailed when login returns non-200 body code', function () {
    Log::spy();

    $loginResponse = new Response(200, [], json_encode(['code' => 500, 'message' => 'Invalid credentials']));

    $client = makeClient([$loginResponse]);
    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());
})->throws(CouldNotSendNotification::class, 'logging in');

// Test 10 (COVR-05): Login network error throws requestFailed
it('throws requestFailed when login request fails with network error', function () {
    Log::spy();

    $loginError = new RequestException('Connection timed out', new Request('POST', 'login'));

    $client = makeClient([$loginError]);
    $channel = new ServiceBusChannel(makeV2Config(), $client);

    $channel->send(new AnonymousNotifiable(), new TestNotification());
})->throws(CouldNotSendNotification::class, 'logging the event');
