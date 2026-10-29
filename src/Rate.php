<?php
declare(strict_types=1);

/** Small sliding-window counter for public forms (registration, password reset): at most $limit hits per $window seconds. */
final class Rate
{
    /** Records a hit; returns false when the limit for this key is already used up. */
    public static function hit(string $kind, string $key, int $limit, int $window): bool
    {
        Db::run('DELETE FROM rate WHERE ts < ?', [time() - 86400]);
        $count = (int) Db::one('SELECT COUNT(*) AS c FROM rate WHERE kind = ? AND k = ? AND ts >= ?', [$kind, $key, time() - $window])['c'];
        if ($count >= $limit) {
            return false;
        }
        Db::run('INSERT INTO rate (kind, k, ts) VALUES (?, ?, ?)', [$kind, $key, time()]);
        return true;
    }
}
