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
 * @property string reference
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

    protected string $reference;

    protected $culture;

    protected $actionType;

    protected $actionReference;

    protected Carbon $createdAt;

    protected $payload;

    protected $route;

    protected $config = [];

    /**
     * ServiceBusEvent constructor.
     */
    public function __construct(protected string $eventType, array $config = [])
    {
        $this->config = $config ?: config('services.service_bus');
        $this->createdAt = Carbon::now();
        $this->reference = $this->generateUUID();
    }

    /**
     * Create an event to be sent to the service bus.
     *
     * Required config:
     * - services.service_bus.from
     * - services.service_bus.version
     */
    public static function create(string $eventType, array $config = []): static
    {
        return new static($eventType, $config);
    }

    /**
     * Source reference for the event.
     *
     * If this is not sent a UUID will be generated and sent with the request.
     */
    public function withReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * ISO representation of the language and culture active on the system when the event was created.
     *
     * This can be set here for each individual event, or it can be set in config services.service_bus.culture
     */
    public function withCulture(string $culture): static
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
     *
     * @throws InvalidConfigException
     */
    public function withAction(string $type, string $reference): static
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
     */
    public function withRoute(string $route): static
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
     * @return $this
     */
    public function withResources(string $resourceName, array $resource): static
    {
        $this->payload[$resourceName] = $resource;

        return $this;
    }

    /**
     * @param array|JsonResource $resource
     *
     * @return this
     */
    public function withResource(string $resourceName, $resource, Request $request = null): static
    {
        if (!is_array($resource)) {
            if ($resource instanceof JsonResource) {
                $resource = $resource->toArray($request);
            } else {
                throw new Exception('Unhandled resource type: ' . $resourceName . ' ' . json_encode($resource));
            }
        }

        $this->payload[$resourceName] = $resource;

        return $this;
    }

    /**
     * @return $this
     */
    public function withPayload(array $payload): static
    {
        $this->payload = [];

        foreach ($payload as $resourceName => $resource) {
            $this->withResource($resourceName, $resource);
        }

        return $this;
    }

    /**
     * Date time of the event creation on the event source in ISO8601/RFC3339 format.
     */
    public function createdAt(Carbon $createdAtDate): static
    {
        $this->createdAt = $createdAtDate;

        return $this;
    }

    /**
     * Returns the culture to be use, will use config services.service_bus.culture if not set on the event.
     */
    protected function getCulture(): string
    {
        return $this->culture ?? $this->config['culture'];
    }

    /**
     * Gets the extra data to be sent as the payload param.
     */
    protected function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Generates a v4 UUID.
     *
     * @throws Throwable
     */
    private function generateUUID(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Return the event as an array that can be sent to the service.
     *
     * @throws Throwable
     */
    public function getParams(): array
    {
        $version = (int) ($this->config['version']);

        if ($version < 2) {
            return [
                'events' => [$this->eventType],
                'venture_reference' => $this->reference,
                'reference' => $this->reference,
                'venture_config_id' => $this->config['venture_config_id'],
                'from' => $this->config['venture_config_id'],
                'created_at' => $this->createdAt->toISOString(),
                'culture' => $this->getCulture(),
                'action_type' => $this->actionType,
                'action_reference' => $this->actionReference,
                'version' => $this->config['version'],
                'route' => $this->route,
                'payload' => $this->getPayload(),
            ];
        }

        return [
            'events' => [$this->eventType],
            'reference' => $this->reference,
            'from' => $this->config['from'] ?? $this->config['node_id'],
            'created_at' => $this->createdAt->toISOString(),
            'version' => $this->config['version'],
            'route' => $this->route,
            'payload' => $this->getPayload(),
        ];
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }
}
