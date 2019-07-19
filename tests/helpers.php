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
        return '';
    }
}
