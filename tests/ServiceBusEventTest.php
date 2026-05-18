<?php

use Carbon\Carbon;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;

function config_v1(): array
{
    return [
        'enabled' => true,
        'venture_config_id' => '123456789',
        'username' => 'username',
        'password' => 'password',
        'version' => '1.0.0',
        'culture' => 'en_GB',
        'endpoint' => 'https://bus.staging.ritdu.tech/v1/',
    ];
}

function config_v2(): array
{
    return [
        'enabled' => true,
        'node_id' => '123456789',
        'username' => 'username',
        'password' => 'password',
        'version' => '2.0.0',
        'endpoint' => 'https://bus.staging.ritdu.tech/v1/',
    ];
}

test(
    'should create service bus event instance',
    function () {
        $serviceBus = new ServiceBusEvent('test', config_v2());

        expect($serviceBus->getEventType())->toEqual('test');
    },
);

test(
    'should create service bus event instance via static call',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2());

        expect($serviceBus->getEventType())->toEqual('test');
    },
);

test(
    'should throw invalid config exception',
    function () {
        ServiceBusEvent::create('test')
            ->withAction('test', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withResources('resources', ['data']);
    },
)->throws(InvalidConfigException::class);

test(
    'should allocate attributes to service bus object',
    function () {
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
    },
);

test(
    'should allocate attributes to service bus object with payload',
    function () {
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
    },
);

test(
    'should return correct event for specific version',
    function () {
        $serviceBusVersion1 = ServiceBusEvent::create('test', config_v1())
            ->withAction('other', uniqid())
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->withPayload(
                [
                    'listing' => [],
                ],
            )
            ->createdAt(Carbon::now());

        $serviceBusVersion2 = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->withPayload(
                [
                    'listing' => [],
                ],
            )
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
    },
);

test(
    'v2 getParams includes debounce_key key',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'listing' => ['reference' => 'listing-123'],
                ],
            );

        expect($serviceBus->getParams())->toHaveKey('debounce_key');
    },
);

test(
    'v1 getParams does not include debounce_key',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v1())
            ->withAction('other', 'a-ref')
            ->withCulture('en')
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'listing' => ['reference' => 'listing-123'],
                ],
            );

        expect($serviceBus->getParams())->not->toHaveKey('debounce_key');
    },
);

test(
    'debounce_key is null when payload is empty',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload([]);

        expect($serviceBus->getParams()['debounce_key'])->toBeNull();
    },
);

test(
    'getParams succeeds when payload was never set (no withPayload/withResource call)',
    function () {
        // Regression: getDebounceKey calls getPayload, which previously assumed non-null payload.
        // An event built without withPayload/withResource must still serialize cleanly with
        // a null debounce_key — not TypeError.
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now());

        $params = $serviceBus->getParams();

        expect($params)->toHaveKey('debounce_key');
        expect($params['debounce_key'])->toBeNull();
        expect($params)->toHaveKey('payload');
        expect($params['payload'])->toEqual([]);
    },
);

test(
    'debounce_key joins all payload entity references with underscores',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'listing' => ['reference' => 'listing-abc'],
                    'user' => ['reference' => 'user-xyz'],
                ],
            );

        expect($serviceBus->getParams()['debounce_key'])
            ->toEqual('listing=listing-abc_user=user-xyz');
    },
);

test(
    'debounce_key is null when any payload entity is missing its reference',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'listing' => ['reference' => 'listing-abc'],
                    'user' => ['name' => 'no reference here'],
                ],
            );

        expect($serviceBus->getParams()['debounce_key'])->toBeNull();
    },
);

test(
    'debounce_key handles a single-entity payload',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'listing' => ['reference' => 'only-one'],
                ],
            );

        expect($serviceBus->getParams()['debounce_key'])
            ->toEqual('listing=only-one');
    },
);

test(
    'debounce_key preserves payload key insertion order',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'user' => ['reference' => 'u-1'],
                    'listing' => ['reference' => 'l-2'],
                    'advertiser' => ['reference' => 'a-3'],
                ],
            );

        expect($serviceBus->getParams()['debounce_key'])
            ->toEqual('user=u-1_listing=l-2_advertiser=a-3');
    },
);

test(
    'debounce_key is null when an entity reference is an empty string (filter() drops falsy)',
    function () {
        $serviceBus = ServiceBusEvent::create('test', config_v2())
            ->withReference(uniqid())
            ->withRoute('api')
            ->createdAt(Carbon::now())
            ->withPayload(
                [
                    'listing' => ['reference' => 'listing-abc'],
                    'user' => ['reference' => ''],
                ],
            );

        expect($serviceBus->getParams()['debounce_key'])->toBeNull();
    },
);
