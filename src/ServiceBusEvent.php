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
    protected $useStaging;

    public function __construct(string $eventType)
    {
        $this->eventType = $eventType;
    }

    /**
     * Create an event to be sent to the service bus.
     *
     * Required config:
     * - services.service_bus.venture_config_id
     * - services.service_bus.version
     *
     * @param string $eventType
     * @return ServiceBusEvent
     */
    public static function create(string $eventType): ServiceBusEvent
    {
        return new static($eventType);
    }

    /**
     * Source reference for the event.
     *
     * If this is not sent a UUID will be generated and sent with the request.
     *
     * @param string $ventureReference
     * @return ServiceBusEvent
     */
    public function withReference(string $ventureReference): ServiceBusEvent
    {
        $this->ventureReference = $ventureReference;
        return $this;
    }

    /**
     * ISO representation of the language and culture active on the system when the event was created
     *
     * This can be set here for each individual event, or it can be set in config services.service_bus.culture
     *
     * @param string $culture
     * @return ServiceBusEvent
     */
    public function withCulture(string $culture): ServiceBusEvent
    {
        $this->culture = $culture;
        return $this;
    }

    /**
     * The type needs to be one of {@link $actionTypes} and represents who initiated the event e.g. a user on the site,
     * an administrator, via an api or internally in the system or app or via a data migration.
     *
     * The reference is who created the event where relevant to the type.  Use this to track e.g. which user created a
     * listing. or that a user registered from facebook
     *
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

    /**
     * Event recipe routing, optional and defaulted to empty.  Can be used in a recipe for example to choose different
     * services because identified as “high_priority” or “testing”, entirely up to the venture how they want to use
     * this to switch on their recipe.
     *
     * @param string $route
     * @return ServiceBusEvent
     */
    public function withRoute(string $route): ServiceBusEvent
    {
        $this->route = $route;
        return $this;
    }

    /**
     * The user that the event applies to, where relevant, eg, the user that logged in.
     *
     * This needs to a Illuminate\Http\Resources\Json\JsonResource\JsonResource representing the user
     *
     * @param $user
     * @return $this
     */
    public function withUser($user)
    {
        $this->users = array(
            $user
        );
        return $this;
    }

    /**
     * Use the staging environment
     *
     * @return $this
     */
    public function onStaging()
    {
        $this->useStaging = true;

        return $this;
    }

    /**
     * Date time of the event creation on the event source in ISO8601/RFC3339 format
     *
     * @param string $createdAtDate
     * @return ServiceBusEvent
     */
    public function createdAt(string $createdAtDate): ServiceBusEvent
    {
        $this->createdAt = $createdAtDate;
        return $this;
    }

    /**
     * Returns the culture to be use, will use config services.service_bus.culture if not set on the event
     *
     * @return string
     */
    protected function getCulture(): string
    {
        return $this->culture ? $this->culture : config('services.service_bus.culture');
    }

    /**
     * Get the venture reference, will generate a UUID if not set on the event
     *
     * @return string
     * @throws \Exception
     */
    protected function getVentureReference(): string
    {
        return $this->ventureReference ? $this->ventureReference : $this->generateUUID('venture_reference');
    }

    /**
     * Gets the extra data to be sent as the payload param
     *
     * @return array
     */
    protected function getPayload(): array
    {
        $payload = array();

        if($this->users){
            $payload['users'] = $this->users;
        }

        return $payload;
    }

    /**
     * Generates a v4 UUID
     *
     * @param string $key
     * @return string
     * @throws \Exception
     */
    private function generateUUID(string $key): string
    {
        $uuid = Uuid::generate(4)->string;
        Log::info('Generating UUID', ['tag' => 'ServiceBus', 'id' => $uuid, 'key' => $key]);
        return $uuid;
    }


    /**
     * Return the event as an array that can be sent to the service
     *
     * @return array
     * @throws \Exception
     */
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

    public function useStaging()
    {
        return $this->useStaging;
    }

    public function getEventType()
    {
        return $this->eventType;
    }
}
