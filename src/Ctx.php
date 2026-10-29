<?php
declare(strict_types=1);

/** The user a request, a worker job or a CLI call acts for. Everything that touches user data reads it from here. */
final class Ctx
{
    public static int $uid = 0;

    public static function uid(): int
    {
        if (self::$uid < 1) {
            throw new RuntimeException('No user in context');
        }
        return self::$uid;
    }

    /** Runs $fn as another user and restores the previous one. */
    public static function as(int $uid, callable $fn): mixed
    {
        $prev = self::$uid;
        self::$uid = $uid;
        try {
            return $fn();
        } finally {
            self::$uid = $prev;
        }
    }
}
