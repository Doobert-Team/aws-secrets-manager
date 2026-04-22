<?php
// Minimal Cache stub for standalone testing
namespace Illuminate\Support\Facades;

class Cache
{
    public static function store($store = null)
    {
        return new class {
            public function get($key) { return null; }
            public function put($key, $value, $ttl) { return true; }
            public function getStore() { return $this; }
            public function lock($key, $ttl) { return $this; }
            public function block($ttl, $callback) { return $callback(); }
        };
    }
}
