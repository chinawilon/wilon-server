<?php

namespace Wilon;

class Log
{
    public static function info(...$args): void
    {
        printf("\033[32m[%s]\033[0m %s\n", date("H:i:s"), self::string($args));
    }

    public static function error(...$args): void
    {
        printf("\033[31m[%s]\033[0m %s\n", date("H:i:s"), self::string($args));
    }

    private static function string(array $args): string
    {
        $msg = '';
        $space = '';
        foreach ($args as $arg) {
            if (is_scalar($arg)) {
                $msg .= $space.$arg;
                $space = ' ';
                continue;
            }
            $msg .= $space . print_r($arg, true);
            $space = ' ';
        }
        return $msg;
    }
}
