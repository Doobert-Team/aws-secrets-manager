<?php
// Minimal stubs for Laravel helpers to allow standalone testing
if (!function_exists('config')) {
    function config($key = null, $default = null) {
        // Values set in tests via config([...]) override the defaults below
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $GLOBALS['__test_config'][$k] = $v;
            }
            return null;
        }
        if (array_key_exists($key, $GLOBALS['__test_config'] ?? [])) {
            return $GLOBALS['__test_config'][$key];
        }
        // Return some sensible defaults for testing
        $defaults = [
            'aws-secrets-manager.check_latency' => false,
            'aws-secrets-manager.enabled' => true,
            'aws-secrets-manager.log_channel' => null,
            'aws-secrets-manager.region' => 'us-west-2',
            'aws-secrets-manager.cache_ttl' => 3600,
            'aws-secrets-manager.cache_store' => 'array',
            'aws-secrets-manager.name' => 'test/secret',
            'aws-secrets-manager.keys_raw' => '',
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
