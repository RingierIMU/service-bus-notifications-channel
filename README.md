# Service Bus Notifications Channel

This is a Laravel package that provides notification channels for
sending notifications to the _RingierSA Service Bus_.

## Installation

Install the package into your project via composer:

```bash
composer require ringiersa/service-bus-notifications-channel
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

## usage

Add something like the following example to a notification class:

```php
use App\Models\Article;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use RingierSA\ServiceBusNotificationsChannel\ServiceBusChannel;
use RingierSA\ServiceBusNotificationsChannel\ServiceBusEvent;

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

## sqs usage

The API endpoint is rate limited, so it's not suitable for high volume notifications.

For high volume notifications, you can send directly to an `SQS` queue in the service bus.

This removes the need to queue it in your app, and provides a more reliable way to send high volume notifications.

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
use RingierSA\ServiceBusNotificationsChannel\ServiceBusSQSChannel;

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
