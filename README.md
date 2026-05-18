# Service Bus Notifications Channel

[![CI](https://github.com/RingierIMU/service-bus-notifications-channel/actions/workflows/main.yml/badge.svg)](https://github.com/RingierIMU/service-bus-notifications-channel/actions/workflows/main.yml)
![PHP Version](https://img.shields.io/badge/php-8.3%2B-777BB4?logo=php&logoColor=white)
![Laravel Version](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel&logoColor=white)

This is a Laravel package that provides notification channels for
sending notifications to the _RingierSA Service Bus_.

## Supported Versions

| PHP | Laravel 11.x | Laravel 12.x | Laravel 13.x |
|-----|:------------:|:------------:|:------------:|
| 8.3 | Yes | Yes | Yes |
| 8.4 | Yes | Yes | Yes |

## Requirements

- PHP 8.3 or higher
- Laravel 11.x, 12.x, or 13.x

## Installation

Install the package into your project via composer:

```bash
composer require ringierimu/service-bus-notifications-channel
```

## Configuration

Add the following to `config/services.php` file:

```php
'service_bus' => [
    'enabled' => env('SERVICE_BUS_ENABLED', true),
    'from' => env('SERVICE_BUS_FROM'),
    'username' => env('SERVICE_BUS_USERNAME'),
    'password' => env('SERVICE_BUS_PASSWORD'),
    'version' => env('SERVICE_BUS_VERSION', '2.0.0'),
    'endpoint' => env('SERVICE_BUS_ENDPOINT', 'https://bus.staging.ritdu.tech/v1/'),
],
```

Add the following to the `.env` file:

```dotenv
SERVICE_BUS_ENABLED=true
SERVICE_BUS_FROM=bus-node-id
SERVICE_BUS_USERNAME=bus-username
SERVICE_BUS_PASSWORD=bus-password
SERVICE_BUS_VERSION=2.0.0
SERVICE_BUS_ENDPOINT=https://bus.staging.ritdu.tech/v1/
```

You can get the `bus-node-id`, `bus-username` and `bus-password` from the _RingierSA_ Service Bus team.

## Usage

Add something like the following example to a notification class:

```php
use App\Models\Article;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusChannel;
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusEvent;

class ArticleCreatedNotification extends Notification
{
    use SerializesModels;

    public function __construct(protected Article $article)
    {
        //
    }

    public function toServiceBus(Notifiable $notifiable): ServiceBusEvent
    {
        return ServiceBusEvent::create('ArticleCreated')
            ->withAction('user', $this->article->user_id)
            ->withCulture('en')
            ->withReference(uniqid())
            ->withPayload([
                'article' => $this->article->toServiceBus(),
            ]);
    }

    public function via($notifiable)
    {
        return [ServiceBusChannel::class];
    }
}
```

Then use an anonymous notifiable to send the notification:

```php
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

$article = Article::create([
    'title' => 'My Article',
    'body' => 'This is my article content',
    'user_id' => 1,
    // ...
]);

(new AnonymousNotifiable)->notify(new MyNotification($article));
```

That will use the `ServiceBusChannel` to send the notification to the _RingierSA_ Service Bus.

## SQS Usage

The API endpoint is rate limited, so it's not suitable for high volume notifications.

For high volume notifications, you can send directly to an `SQS` queue in the service bus.

This removes the need to queue it in your app, and provides a more reliable way to send high volume notifications.

### Configuration

Add the following to your config:

```php
'service_bus' => [
    '...',
    'sqs' => [
        'region' => env('SERVICE_BUS_SQS_REGION', 'eu-west-1'),
        'queue_url' => env('SERVICE_BUS_SQS_QUEUE_URL'),
        'key' => env('SERVICE_BUS_SQS_KEY'),
        'secret' => env('SERVICE_BUS_SQS_SECRET'),
    ],
],
```

Also add the following to the `.env` file:

```dotenv
SERVICE_BUS_SQS_REGION=eu-west-1
SERVICE_BUS_SQS_QUEUE_URL=queue-url
SERVICE_BUS_SQS_KEY=key
SERVICE_BUS_SQS_SECRET=secret
```

The values for `queue-url`, `key` and `secret` can be obtained from the _RingierSA_ Service Bus team.

The next change is to send the service bus notifications via the `ServiceBusSQSChannel` by changing the notification class:

```php
use Ringierimu\ServiceBusNotificationsChannel\ServiceBusSQSChannel;

class ArticleCreatedNotification extends Notification
{
    // Everything else is the same as before

    public function via($notifiable)
    {
        return [ServiceBusSQSChannel::class];
    }
}
```

Now the notification will be sent directly to an `SQS` queue in the service bus, instead of via the API endpoint.

We recommend you do not queue the notification. Send it `afterResponse`, the time to send the notification to `SQS` is minimal.

### Queue type (FIFO vs standard)

Both FIFO and standard SQS queues are supported. The channel detects the queue type from the URL:

- URLs ending in `.fifo` are treated as FIFO queues. `MessageGroupId` is routed per primary entity for known event types (e.g. `{from}_listing={reference}`, `{from}_user={reference}`) so FIFO ordering is preserved per entity rather than globally serialised; unknown event types fall back to bare `{from}`. `MessageDeduplicationId` is set to the `md5` of the message body so dedup works whether or not `ContentBasedDeduplication` is enabled on the queue.
- All other URLs are treated as standard queues — neither field is sent.

### Credential refresh on transient AWS auth errors

If the AWS SDK returns a stale-credential error (`ExpiredToken`, `ExpiredTokenException`, `InvalidClientTokenId`, `UnrecognizedClientException`, `RequestExpired`, `TokenRefreshRequired`), the channel rebuilds its SQS client from the current config and retries the send once. Non-credential errors are not retried and bubble up to the caller.

If you cache Laravel config (`php artisan config:cache`), remember to clear it (`php artisan config:clear`) when rotating AWS credentials — the rebuild reads the same cached values otherwise.

### debounce_key

v2 event payloads include a `debounce_key` field derived from every payload entity's `reference` (e.g. `listing=abc_user=42`). It is emitted as message body data — downstream consumers can use it to dedupe at the application layer over the full reference combination, independent of SQS's content-based dedup. The field is `null` if any payload entity is missing a `reference`.

## Testing

```bash
composer test
```

This runs the [Pest](https://pestphp.com/) test suite.
