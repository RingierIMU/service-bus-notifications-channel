<?php

if (!function_exists('config')) {
    /**
     * Mocked Laravel helper config method.
     *
     * @param array|string $key
     * @param mixed        $default
     *
     * @return string
     */
    function config($key = null, $default = null)
    {
        return config_v1();
    }
}

if (!function_exists('config_v1')) {
    /**
     * Mocked Laravel helper config values for v1 of eventbus.
     *
     * @return array
     */
    function config_v1()
    {
        return [
            'enabled' => true,
            'venture_config_id' => '123456789',
            'username' => 'username',
            'password' => 'password',
            'version' => '1.0.0',
            'culture' => 'en_GB',
            'endpoint' => 'https://bus.staging.ritdu.tech/v1/',
        ];
    }
}

if (!function_exists('config_v2')) {
    /**
     * Mocked Laravel helper config values for v2 of the eventbus.
     *
     * @return array
     */
    function config_v2()
    {
        return [
            'enabled' => true,
            'node_id' => '123456789',
            'username' => 'username',
            'password' => 'password',
            'version' => '2.0.0',
            'endpoint' => 'https://bus.staging.ritdu.tech/v1/',
        ];
    }
}
