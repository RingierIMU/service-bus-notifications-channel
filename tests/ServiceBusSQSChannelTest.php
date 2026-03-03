<?php

use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusSQSChannel;
use Ringierimu\ServiceBusNotificationsChannel\Tests\TestNotification;

function makeSqsConfig(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'node_id' => '123456789',
        'from' => 'test-node',
        'version' => '2.0.0',
        'dont_report' => [],
        'sqs' => [
            'region' => 'us-east-1',
            'key' => 'fake-key',
            'secret' => 'fake-secret',
            'queue_url' => 'https://sqs.us-east-1.amazonaws.com/123/test-queue',
        ],
    ], $overrides);
}

it('sends message to SQS successfully', function () {
    Log::spy();

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldReceive('sendMessage')
        ->once()
        ->with(Mockery::on(function ($args) {
            return isset($args['QueueUrl'])
                && $args['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123/test-queue'
                && isset($args['MessageBody'])
                && json_decode($args['MessageBody'], true) !== null
                && isset($args['MessageGroupId']);
        }))
        ->andReturn(new Result(['MessageId' => 'test-msg-id']));

    $channel = new ServiceBusSQSChannel(makeSqsConfig(), $sqsMock);

    $channel->send(new AnonymousNotifiable(), new TestNotification());

    Log::shouldHaveReceived('info')->once();
});

it('logs debug when disabled and event not in dont_report', function () {
    Log::shouldReceive('debug')
        ->once()
        ->with(
            Mockery::pattern('/test.*\[disabled\]/'),
            Mockery::type('array'),
        );

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldNotReceive('sendMessage');

    $channel = new ServiceBusSQSChannel(
        makeSqsConfig(['enabled' => false, 'dont_report' => []]),
        $sqsMock,
    );

    $channel->send(new AnonymousNotifiable(), new TestNotification());
});

it('suppresses log when disabled and event in dont_report', function () {
    Log::shouldReceive('debug')->never();

    $sqsMock = Mockery::mock(SqsClient::class);
    $sqsMock->shouldNotReceive('sendMessage');

    $channel = new ServiceBusSQSChannel(
        makeSqsConfig(['enabled' => false, 'dont_report' => ['test']]),
        $sqsMock,
    );

    $channel->send(new AnonymousNotifiable(), new TestNotification());
});
