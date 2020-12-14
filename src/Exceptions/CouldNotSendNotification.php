<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Class CouldNotSendNotification.
 */
class CouldNotSendNotification extends Exception
{
    /**
     * @param Throwable $exception
     *
     * @return CouldNotSendNotification
     */
    public static function authFailed(Throwable $exception)
    {
        Log::error(
            'Could not get an auth token from the server',
            [
                'exception' => $exception,
                'tags' => [
                    'service-bus',
                ],
            ]
        );

        return new static('Could not get an auth token from the server: ' . $exception->getMessage());
    }

    /**
     * @param ResponseInterface $response
     *
     * @return CouldNotSendNotification
     */
    public static function loginFailed(ResponseInterface $response)
    {
        Log::error(
            'Something went wrong logging in',
            [
                'response' => [
                    'statusCode' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                ],
                'tags' => [
                    'service-bus',
                ],
            ]
        );

        return new static('Something went wrong logging in');
    }

    /**
     * @param Throwable $exception
     *
     * @return CouldNotSendNotification
     */
    public static function requestFailed(Throwable $exception)
    {
        Log::error(
            'Something went wrong logging the event',
            [
                'exception' => $exception,
                'tags' => [
                    'service-bus',
                ],
            ]
        );

        return new static('Something went wrong logging the event: ' . $exception->getMessage());
    }
}
