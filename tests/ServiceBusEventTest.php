<?php

use Carbon\Carbon;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;


test('should create service bus event instance', function () {
    $serviceBus = new ServiceBusEvent('test', config_v2());

    expect($serviceBus->getEventType())->toEqual('test');
});

test('should create service bus event instance via static call', function () {
    $serviceBus = ServiceBusEvent::create('test', config_v2());

    expect($serviceBus->getEventType())->toEqual('test');
});

test('should throw invalid config exception', function () {
    ServiceBusEvent::create('test')
        ->withAction('test', uniqid())
        ->withCulture('en')
        ->withReference(uniqid())
        ->withRoute('api')
        ->createdAt(Carbon::now())
        ->withResources('resources', ['data']);
})->throws(InvalidConfigException::class);

test('should allocate attributes to service bus object', function () {
    $resource = [
        'user' => 'John Doe',
        'email' => 'john@doe.com',
        'phone' => '0123456789',
    ];

    $serviceBus = ServiceBusEvent::create('test', config_v2())
        ->withAction('other', uniqid())
        ->withCulture('en')
        ->withReference(uniqid())
        ->withRoute('api')
        ->createdAt(Carbon::now())
        ->withResources('resource', $resource);

    $serviceBusData = $serviceBus->getParams();

    expect($serviceBusData)->not->toBeEmpty();
    expect($serviceBusData)->toHaveKey('events');
    expect($serviceBusData)->toHaveKey('payload');
    expect($serviceBusData['payload'])->toHaveKey('resource');
    expect($serviceBusData['events'])->toContain('test');

    expect($serviceBusData['payload']['resource'])->toEqual($resource);
});

test('should allocate attributes to service bus object with payload', function () {
    $payload = [
        'object' => [
            'user' => 'John Doe',
            'email' => 'john@doe.com',
            'phone' => '0123456789',
        ],
    ];

    $serviceBus = ServiceBusEvent::create('test', config_v2())
        ->withAction('other', uniqid())
        ->withCulture('en')
        ->withReference(uniqid())
        ->withRoute('api')
        ->createdAt(Carbon::now())
        ->withPayload($payload);

    $serviceBusData = $serviceBus->getParams();

    expect($serviceBusData)->not->toBeEmpty();
    expect($serviceBusData)->toHaveKey('events');
    expect($serviceBusData)->toHaveKey('payload');
    expect($serviceBusData['events'])->toContain('test');

    expect($serviceBusData['payload'])->toEqual($payload);
});

test('should return correct event for specific version', function () {
    $serviceBusVersion1 = ServiceBusEvent::create('test', config_v1())
        ->withAction('other', uniqid())
        ->withCulture('en')
        ->withReference(uniqid())
        ->withRoute('api')
        ->withPayload([
            'listing' => [],
        ])
        ->createdAt(Carbon::now());

    $serviceBusVersion2 = ServiceBusEvent::create('test', config_v2())
        ->withReference(uniqid())
        ->withRoute('api')
        ->withPayload([
            'listing' => [],
        ])
        ->createdAt(Carbon::now());

    $serviceBusDataVersion1 = $serviceBusVersion1->getParams();
    $serviceBusDataVersion2 = $serviceBusVersion2->getParams();

    expect($serviceBusDataVersion1)->not->toBeEmpty();
    expect($serviceBusDataVersion2)->not->toBeEmpty();

    expect($serviceBusDataVersion1)->toHaveKey('events');
    expect($serviceBusDataVersion1)->toHaveKey('payload');
    expect($serviceBusDataVersion1)->toHaveKey('from');
    expect($serviceBusDataVersion1)->toHaveKey('venture_config_id');
    expect($serviceBusDataVersion1)->toHaveKey('venture_reference');
    expect($serviceBusDataVersion1)->toHaveKey('reference');

    expect($serviceBusDataVersion2)->toHaveKey('from');
    expect($serviceBusDataVersion2)->toHaveKey('events');
    expect($serviceBusDataVersion2)->toHaveKey('payload');
    expect($serviceBusDataVersion2)->toHaveKey('reference');

    expect($serviceBusDataVersion1['venture_config_id'])->not->toBeEmpty();
    expect($serviceBusDataVersion1['venture_reference'])->not->toBeEmpty();

    expect($serviceBusDataVersion2['from'])->not->toBeEmpty();
    expect($serviceBusDataVersion2['reference'])->not->toBeEmpty();

    expect($serviceBusDataVersion1['events'])->toContain('test');
    expect($serviceBusDataVersion2['events'])->toContain('test');
});
