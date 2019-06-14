<?php

namespace NotificationChannels\ServiceBus\Exceptions;

class CouldNotSendNotification extends \Exception
{
    public static function serviceRespondedWithAnError($response)
    {
        return new static("Descriptive error message.");
    }

    public static function authFailed($response)
    {
        return new static("Could not get an auth token from the server: ".$response);
    }
}
