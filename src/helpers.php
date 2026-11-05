<?php

if (!function_exists('flamixml_log')) {
    /** flamix_log() into the flamixml channel: FLAMIX_LOGS_PATH/logs/flamixml/. */
    function flamixml_log(string $message, array $context = []): \Monolog\Logger
    {
        return flamix_log($message, $context, 'flamixml');
    }
}

if (!function_exists('flamixml_config')) {
    /** A key of FLAMIXML_EXCHANGE_DIR_PATH/config.php; a missing key with a null default returns the whole array. */
    function flamixml_config(string $key, $default = null)
    {
        $config = include FLAMIXML_EXCHANGE_DIR_PATH . '/config.php';
        return $config[$key] ?? (($default === null) ? $config : $default);
    }
}

if (!function_exists('flamixml_response')) {
    /** Prints the lines joined by PHP_EOL and ends the request with die(). */
    function flamixml_response($result)
    {
        echo (is_array($result)) ? implode(PHP_EOL, $result) : $result;
        die();
    }
}

if (!function_exists('flamixml_response_by_type')) {
    /** Sends response_header, then "success"/"progress"/"failure" and the description; any other type becomes failure. */
    function flamixml_response_by_type(string $type = 'failure', string $description = '')
    {
        flamixml_log('In 1C was send a response of the type: ' . $type);

        foreach (flamixml_config('response_header', ['Content-Type' => 'Content-Type: text/plain; charset=utf-8']) as $header)
            header($header);

        if (in_array($type, ['success', 'progress']))
            flamixml_response([$type, $description]);

        flamixml_response(['failure', $description]);
    }
}
