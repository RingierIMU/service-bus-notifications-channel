<?php

namespace Ringierimu\ServiceBusNotificationsChannel\Exceptions;

class CouldNotSendNotification extends \Exception
{
    public static function authFailed($response)
    {
        return new static("Could not get an auth token from the server: ".$response);
    }

    public static function requestFailed($exception)
    {
        return new static("Something went wrong logging the event: ".$exception);
    }
}
