<?php
namespace Illuminate\Cache;

class Repository {
    public function get($key) { return null; }
    public function put($key, $value, $ttl) { return true; }
    public function getStore() { return $this; }
    public function lock($key, $ttl) { return $this; }
    public function block($ttl, $callback) { return $callback(); }
}
