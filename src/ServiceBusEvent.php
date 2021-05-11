<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Ramsey\Uuid\Uuid;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Throwable;

/**
 * Class ServiceBusEvent.
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
        'other',
    ];

    protected $eventType;
    protected $ventureReference;
    protected $culture;
    protected $actionType;
    protected $actionReference;
    protected $createdAt;
    protected $payload;
    protected $route;
    protected $ventureConfig = [];

    /**
     * ServiceBusEvent constructor.
     *
     * @param string $eventType
     * @param array  $ventureConfig
     */
    public function __construct(string $eventType, array $ventureConfig = [])
    {
        $this->eventType = $eventType;
        $this->ventureConfig = $ventureConfig ?: config('services.service_bus');
        $this->createdAt = Carbon::now();
        $this->ventureReference = $this->generateUUID();
    }

    /**
     * Create an event to be sent to the service bus.
     *
     * Required config:
     * - services.service_bus.venture_config_id
     * - services.service_bus.version
     *
     * @param string $eventType
     * @param array  $ventureConfig
     *
     * @return ServiceBusEvent
     */
    public static function create(string $eventType, array $ventureConfig = []): self
    {
        return new static($eventType, $ventureConfig);
    }

    /**
     * Source reference for the event.
     *
     * If this is not sent a UUID will be generated and sent with the request.
     *
     * @param string $ventureReference
     *
     * @return ServiceBusEvent
     */
    public function withReference(string $ventureReference): self
    {
        $this->ventureReference = $ventureReference;

        return $this;
    }

    /**
     * ISO representation of the language and culture active on the system when the event was created.
     *
     * This can be set here for each individual event, or it can be set in config services.service_bus.culture
     *
     * @param string $culture
     *
     * @return ServiceBusEvent
     */
    public function withCulture(string $culture): self
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
     *
     * @throws InvalidConfigException
     *
     * @return ServiceBusEvent
     */
    public function withAction(string $type, string $reference): self
    {
        if (in_array($type, self::$actionTypes)) {
            $this->actionType = $type;
            $this->actionReference = $reference;
        } else {
            throw new InvalidConfigException('Action type must be on of the following: ' . print_r(self::$actionTypes, true));
        }

        return $this;
    }

    /**
     * Event recipe routing, optional and defaulted to empty.  Can be used in a recipe for example to choose different
     * services because identified as “high_priority” or “testing”, entirely up to the venture how they want to use
     * this to switch on their recipe.
     *
     * @param string $route
     *
     * @return ServiceBusEvent
     */
    public function withRoute(string $route): self
    {
        $this->route = $route;

        return $this;
    }

    /**
     * The entity that the event applies to, where relevant, eg, the user that logged in.
     *
     * This needs to a Illuminate\Http\Resources\Json\JsonResource\JsonResource representing the entity
     *
     * @deprecated Use withResource and withPayload instead.
     *
     * @param string $resourceName
     * @param array  $resource
     *
     * @return $this
     */
    public function withResources(string $resourceName, array $resource)
    {
        $this->payload[$resourceName] = $resource;

        return $this;
    }

    /**
     * @param string $resourceName
     * @param array|JsonResource $resource
     * @param null|Request $request
     *
     * @return this
     */
    public function withResource(string $resourceName, $resource, Request $request = null): self
    {
        if (!is_array($resource)) {
            if ($resource instanceof JsonResource) {
                $resource = $resource->toArray($request);
            } else {
                throw new Exception('Unhandled resource type: ' . json_encode($resource));
            }
        }

        $this->payload[$resourceName] = $resource;

        return $this;
    }

    /**
     * @param array $payload
     *
     * @return $this
     */
    public function withPayload(array $payload)
    {
        $this->payload = [];

        foreach ($payload as $resourceName => $resource) {
            $this->withResource($resourceName, $resource);
        }

        return $this;
    }

    /**
     * Date time of the event creation on the event source in ISO8601/RFC3339 format.
     *
     * @param Carbon $createdAtDate
     *
     * @return ServiceBusEvent
     */
    public function createdAt(Carbon $createdAtDate): self
    {
        $this->createdAt = $createdAtDate;

        return $this;
    }

    /**
     * Returns the culture to be use, will use config services.service_bus.culture if not set on the event.
     *
     * @return string
     */
    protected function getCulture(): string
    {
        return $this->culture ?? $this->ventureConfig['culture'];
    }

    /**
     * Gets the extra data to be sent as the payload param.
     *
     * @return array
     */
    protected function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Generates a v4 UUID.
     *
     * @throws Throwable
     *
     * @return string
     */
    private function generateUUID(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Return the event as an array that can be sent to the service.
     *
     * @throws Throwable
     *
     * @return array
     */
    public function getParams(): array
    {
        return [
            'events' => [$this->eventType],
            'venture_reference' => $this->ventureReference,
            'venture_config_id' => $this->ventureConfig['venture_config_id'],
            'created_at' => $this->createdAt->toIso8601String(),
            'culture' => $this->getCulture(),
            'action_type' => $this->actionType,
            'action_reference' => $this->actionReference,
            'version' => $this->ventureConfig['version'],
            'route' => $this->route,
            'payload' => $this->getPayload(),
        ];
    }

    /**
     * @return string
     */
    public function getEventType()
    {
        return $this->eventType;
    }
}
