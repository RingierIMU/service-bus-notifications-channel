<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Exceptions;

/**
 * Class CouldNotSendNotification
 * @package Ringierimu\ServiceBusNotificationsChannel\Exceptions
 */
class CouldNotSendNotification extends \Exception
{
    /**
     * @param $response
     * @return CouldNotSendNotification
     */
    public static function authFailed($response)
    {
        return new static("Could not get an auth token from the server: ".$response);
    }

    /**
     * @param $exception
     * @return CouldNotSendNotification
     */
    public static function requestFailed($exception)
    {
        Log::error('Something went wrong logging the event: ' . $exception->getMessage(), ['tag' => 'ServiceBus']);

        return new static("Something went wrong logging the event: ".$exception);
    }
}
