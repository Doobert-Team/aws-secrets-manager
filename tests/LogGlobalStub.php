<?php
// Global Log stub for standalone testing
if (!class_exists('Log')) {
    class Log {
        public static function channel($channel) { return new static; }
        public static function log($level, $message, array $context = []) {}
    }
}
