<?php


namespace Battis\PersistentObject;


class External
{
    private static $instance;

    private function __construct()
    {
    }

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function is_callable($callable)
    {
        return is_callable($callable);
    }
}
