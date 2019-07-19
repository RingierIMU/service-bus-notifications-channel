<?php namespace Ringierimu\ServiceBusNotificationsChannel\Exceptions;

use \Exception;
use \Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Class CouldNotSendNotification
 * @package Ringierimu\ServiceBusNotificationsChannel\Exceptions
 */
class CouldNotSendNotification extends Exception
{
    /**
     * @param Throwable $exception
     * @return CouldNotSendNotification
     */
    public static function authFailed(Throwable $exception)
    {
        Log::error("Could not get an auth token from the server: " . $exception->getMessage(), ['tag' => 'ServiceBus']);
        Log::error($exception->getTraceAsString(), ['tag' => 'ServiceBus']);

        return new static("Could not get an auth token from the server: " . $exception->getMessage());
    }

    /**
     * @param Throwable $exception
     * @return CouldNotSendNotification
     */
    public static function requestFailed(Throwable $exception)
    {
        Log::error('Something went wrong logging the event: ' . $exception->getMessage(), ['tag' => 'ServiceBus']);
        Log::error($exception->getTraceAsString(), ['tag' => 'ServiceBus']);

        return new static("Something went wrong logging the event: " . $exception->getMessage());
    }
}
