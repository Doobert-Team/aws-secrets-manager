<?php
// Minimal stubs for Laravel helpers to allow standalone testing
if (!function_exists('config')) {
    function config($key, $default = null) {
        // Return some sensible defaults for testing
        $defaults = [
            'services.aws_secrets.check_latency' => false,
            'services.aws_secrets.enabled' => true,
            'services.aws_secrets.log_channel' => null,
            'services.aws_secrets.region' => 'us-west-2',
            'services.aws_secrets.cache_ttl' => 3600,
            'services.aws_secrets.cache_store' => 'array',
            'services.aws_secrets.name' => 'test/secret',
            'app.env' => 'testing',
            'app.name' => 'test-app',
            'logging.default' => 'stack',
        ];
        return $defaults[$key] ?? $default;
    }
}
if (!function_exists('now')) {
    function now() {
        return new class {
            public function addSeconds($s) { return time() + $s; }
        };
    }
}
