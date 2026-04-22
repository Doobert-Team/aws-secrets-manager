<?php
// Minimal Log stub for standalone testing
namespace Illuminate\Support\Facades;

class Log
{
    public static function channel($channel)
    {
        return new static;
    }
    public static function log($level, $message, array $context = [])
    {
        // Optionally, output to stdout for debug
        // echo "[$level] $message\n";
    }
}
