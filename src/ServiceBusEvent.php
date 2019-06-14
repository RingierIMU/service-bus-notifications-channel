<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use Aws\Api\Service;
use http\Exception\InvalidArgumentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Webpatser\Uuid\Uuid;

class ServiceBusEvent
{

    public static $actionTypes = [
        'user',
        'admin',
        'api',
        'system',
        'app',
        'migration',
        'other'
    ];

    protected $eventType;
    protected $ventureReference;
    protected $culture;
    protected $actionType;
    protected $actionReference;
    protected $createdAt;
    protected $payload;
    protected $route;
    protected $users;

    public function __construct(string $eventType)
    {
        $this->eventType = $eventType;
    }

    public static function create(string $eventType): ServiceBusEvent
    {
        return new static($eventType);
    }

    public function withReference(string $ventureReference): ServiceBusEvent
    {
        $this->ventureReference = $ventureReference;
        return $this;
    }

    public function withCulture(string $culture): ServiceBusEvent
    {
        $this->culture = $culture;
        return $this;
    }

    /**
     * @param string $type
     * @param string $reference
     * @return ServiceBusEvent
     * @throws InvalidConfigException
     */
    public function withAction(string $type, string $reference): ServiceBusEvent
    {
        if(in_array($type, ServiceBusEvent::$actionTypes)){
            $this->actionType = $type;
            $this->actionReference = $reference;
        } else {
            throw new InvalidConfigException('Action type must be on of the following: ' . ServiceBusEvent::$actionTypes);
        }

        return $this;
    }

    public function withRoute(string $route): ServiceBusEvent
    {
        $this->route = $route;
        return $this;
    }

    public function withUser($user)
    {
        $this->users = array(
            $user
        );
        return $this;
    }

    public function createdAt(string $createdAtDate): ServiceBusEvent
    {
        $this->createdAt = $createdAtDate;
        return $this;
    }

    protected function getCulture(): string
    {
        return $this->culture ? $this->culture : config('services.service_bus.culture');
    }

    protected function getVentureReference(): string
    {
        return $this->ventureReference ? $this->ventureReference : $this->generateUUID('venture_reference');
    }

    protected function getPayload(): array
    {
        $payload = array();

        if($this->users){
            $payload['users'] = $this->users;
        }

        return $payload;
    }

    private function generateUUID(string $key): string
    {
        $uuid = Uuid::generate(4)->string;
        Log::info('Generating UUID '.$uuid.' for '.$key, ['ServiceBusEvent']);
        return $uuid;
    }

    public function getParams(): array
    {
        return array (
            'events' => [$this->eventType],
            'venture_reference' => $this->getVentureReference(),
            'venture_config_id' => config('services.service_bus.venture_config_id'),
            'created_at' => $this->createdAt,
            'culture' => $this->getCulture(),
            'action_type' => $this->actionType,
            'action_reference' => $this->actionReference,
            'version' => config('services.service_bus.version'),
            'route' => $this->route,
            'payload' => $this->getPayload()
        );
    }
}
