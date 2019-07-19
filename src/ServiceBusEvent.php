<?php namespace Ringierimu\ServiceBusNotificationsChannel;

use Carbon\Carbon;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Throwable;

/**
 * Class ServiceBusEvent
 * @package Ringierimu\ServiceBusNotificationsChannel
 *
 * @property string eventType
 * @property string ventureReference
 * @property string culture
 * @property string actionType
 * @property string actionReference
 * @property Carbon createdAt
 * @property array payload
 * @property string route
 */
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

    /**
     * ServiceBusEvent constructor.
     * @param string $eventType
     */
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
        if (in_array($type, ServiceBusEvent::$actionTypes)) {
            $this->actionType = $type;
            $this->actionReference = $reference;
        } else {
            throw new InvalidConfigException('Action type must be on of the following: ' . print_r(ServiceBusEvent::$actionTypes, true));
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
     * The entity that the event applies to, where relevant, eg, the user that logged in.
     *
     * This needs to a Illuminate\Http\Resources\Json\JsonResource\JsonResource representing the entity
     *
     * @param string $resourceName
     * @param array $resource
     * @return $this
     */
    public function withResources(string $resourceName, array $resource)
    {
        $this->payload[$resourceName][] = $resource;

        return $this;
    }

    /**
     * Date time of the event creation on the event source in ISO8601/RFC3339 format
     *
     * @param Carbon $createdAtDate
     * @return ServiceBusEvent
     */
    public function createdAt(Carbon $createdAtDate): ServiceBusEvent
    {
        $this->createdAt = $createdAtDate;

        return $this;
    }

    /**
     * Returns the culture to be use, will use config services.service_bus.culture if not set on the event
     *
     * @return string
     * @throws BindingResolutionException
     */
    protected function getCulture(): string
    {
        return $this->culture ?? config('services.service_bus.culture');
    }

    /**
     * Get the venture reference, will generate a UUID if not set on the event
     *
     * @return string
     * @throws Throwable
     */
    protected function getVentureReference(): string
    {
        return $this->ventureReference ?? $this->generateUUID('venture_reference');
    }

    /**
     * Gets the extra data to be sent as the payload param
     *
     * @return array
     */
    protected function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Generates a v4 UUID
     *
     * @param string $key
     * @return string
     * @throws Throwable
     */
    private function generateUUID(string $key): string
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'php.net');

        Log::info('Generating UUID', ['tag' => 'ServiceBus', 'id' => $uuid->toString(), 'key' => $key]);

        return $uuid->toString();
    }


    /**
     * Return the event as an array that can be sent to the service
     *
     * @return array
     * @throws Throwable
     */
    public function getParams(): array
    {
        return array(
            'events'            => [$this->eventType],
            'venture_reference' => $this->getVentureReference(),
            'venture_config_id' => config('services.service_bus.venture_config_id'),
            'created_at'        => $this->createdAt ? $this->createdAt->toDateTimeString() : Carbon::now()->toDateTimeString(),
            'culture'           => $this->getCulture(),
            'action_type'       => $this->actionType,
            'action_reference'  => $this->actionReference,
            'version'           => config('services.service_bus.version'),
            'route'             => $this->route,
            'payload'           => $this->getPayload()
        );
    }

    /**
     * @return string
     */
    public function getEventType()
    {
        return $this->eventType;
    }
}
