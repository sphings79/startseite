<?php
declare(strict_types=1);

/**
 * Background worker: thumbnails (dials with thumb_status = 'pending': logo discovery, screenshots, image downloads),
 * page descriptions for the search index, scheduled backups and link checks (manual requests first, then whatever is due according to the check interval).
 *
 *   php bin/worker.php          run forever (systemd / container)
 *   php bin/worker.php --once   process the current queue and exit
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$once = in_array('--once', $argv, true);
$lastBackupTry = 0;
$poll = max(1, (int) Config::get('worker_poll_seconds'));
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, static function () use (&$running): void {
            $running = false;
        });
    }
}

// Jobs left in 'running' by a crashed worker go back to the queue.
Db::run("UPDATE dials SET thumb_status = 'pending' WHERE thumb_status = 'running'");
Db::run('UPDATE dials SET check_req = 1 WHERE check_req = 2');

while ($running) {
    $dial = Db::one("SELECT * FROM dials WHERE thumb_status = 'pending' ORDER BY id LIMIT 1");
    Ctx::$uid = (int) ($dial['user_id'] ?? 0);
    if ($dial === null) {
        $job = LinkCheck::next();
        if ($job !== null) {
            [$d, $manual] = $job;
            Ctx::$uid = (int) $d['user_id'];
            Db::run('UPDATE dials SET check_req = 2 WHERE id = ?', [$d['id']]);
            fwrite(STDOUT, date('H:i:s') . " check #{$d['id']} {$d['url']} ... ");
            try {
                LinkCheck::run($d, $manual);
                $after = Db::one('SELECT check_status, check_reason FROM dials WHERE id = ?', [$d['id']]);
                fwrite(STDOUT, ($after['check_status'] ?? '?') . ' ' . ($after['check_reason'] ?? '') . "\n");
            } catch (Throwable $e) {
                Db::run("UPDATE dials SET check_req = 0, check_at = ?, check_reason = ? WHERE id = ?", [time(), mb_substr($e->getMessage(), 0, 200), $d['id']]);
                fwrite(STDOUT, 'error ' . $e->getMessage() . "\n");
            }
            continue;
        }
        if (time() - $lastBackupTry > 600) {
            $lastBackupTry = time();
            foreach (Db::all("SELECT id FROM users WHERE status = 'active' ORDER BY id") as $u) {
                Ctx::$uid = (int) $u['id'];
                if (!Backup::due()) {
                    continue;
                }
                try {
                    fwrite(STDOUT, date('H:i:s') . " backup user {$u['id']} " . Backup::create() . "\n");
                } catch (Throwable $e) {
                    fwrite(STDOUT, date('H:i:s') . " backup user {$u['id']} failed: " . $e->getMessage() . "\n");
                }
            }
        }
        $meta = PageMeta::next();
        if ($meta !== null) {
            Ctx::$uid = (int) $meta['user_id'];
            PageMeta::refresh($meta);
            continue;
        }
        if ($once) {
            break;
        }
        sleep($poll);
        continue;
    }
    Db::run("UPDATE dials SET thumb_status = 'running', thumb_attempts = thumb_attempts + 1 WHERE id = ?", [$dial['id']]);
    fwrite(STDOUT, date('H:i:s') . " #{$dial['id']} {$dial['url']} ... ");
    try {
        Thumbs::process($dial);
        $after = Db::one('SELECT thumb_status, thumb_kind, thumb_error FROM dials WHERE id = ?', [$dial['id']]);
        fwrite(STDOUT, ($after['thumb_status'] ?? '?') . ' ' . ($after['thumb_kind'] ?? $after['thumb_error'] ?? '') . "\n");
    } catch (Throwable $e) {
        Db::run("UPDATE dials SET thumb_status = 'failed', thumb_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 200), $dial['id']]);
        fwrite(STDOUT, 'failed ' . $e->getMessage() . "\n");
    }
}
