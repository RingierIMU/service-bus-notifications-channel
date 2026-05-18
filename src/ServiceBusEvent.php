<?php

namespace Ringierimu\ServiceBusNotificationsChannel;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Ramsey\Uuid\Uuid;
use Ringierimu\ServiceBusNotificationsChannel\Exceptions\InvalidConfigException;
use Throwable;

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

    public function __construct(protected string $eventType, array $config = [])
    {
        $this->config = $config ?: config('services.service_bus');
        $this->createdAt = Carbon::now();
        $this->reference = $this->generateUUID();
    }

    /**
     * Generates a v4 UUID.
     *
     * @throws Throwable
     */
    protected function generateUUID(): string
    {
        return Uuid::uuid4()->toString();
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
        if (!in_array($type, self::$actionTypes)) {
            throw new InvalidConfigException(
                'Action type must be one of the following: ' . implode(', ', self::$actionTypes),
            );
        }

        $this->actionType = $type;
        $this->actionReference = $reference;

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
     * @deprecated Use withResource and withPayload instead.
     */
    public function withResources(string $resourceName, array $resource): static
    {
        $this->payload[$resourceName] = $resource;

        return $this;
    }

    public function withPayload(array $payload): static
    {
        $this->payload = [];

        foreach ($payload as $resourceName => $resource) {
            $this->withResource($resourceName, $resource);
        }

        return $this;
    }

    /**
     * @param array|JsonResource $resource
     *
     * @throws InvalidConfigException
     */
    public function withResource(string $resourceName, $resource, Request|null $request = null): static
    {
        if (!is_array($resource)) {
            if (!$resource instanceof JsonResource) {
                throw new InvalidConfigException(
                    'Unhandled resource type: ' . $resourceName . ' ' . json_encode($resource),
                );
            }

            $resource = $resource->toArray($request);
        }

        $this->payload[$resourceName] = $resource;

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
            'debounce_key' => $this->getDebounceKey(),
            'created_at' => $this->createdAt->toISOString(),
            'version' => $this->config['version'],
            'route' => $this->route,
            'payload' => $this->getPayload(),
        ];
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
        return $this->payload ?? [];
    }

    protected function getDebounceKey(): string|null
    {
        $debounceKV = collect($this->getPayload())
            ->mapWithKeys(
                fn ($value, $key) => [$key => $value['reference'] ?? null],
            );

        $debounceKVCount = $debounceKV->count();
        if (!$debounceKVCount) {
            return null;
        }

        $debounceKeys = $debounceKV->filter();
        if ($debounceKeys->count() !== $debounceKVCount) {
            return null;
        }

        return $debounceKeys
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('_');
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }
}
