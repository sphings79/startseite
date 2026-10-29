<?php
declare(strict_types=1);

/**
 * JSON endpoints for the password-less passkey login:
 *   POST /login/passkey/options
 *   POST /login/passkey/verify    {next, credential}
 * Failed attempts count against the same per-IP limit as wrong passwords.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new HttpError(405, 'Method not allowed');
}
Http::assertSameOrigin();
$in = Http::body();
$ip = Http::clientIp();
if (($wait = Auth::throttled($ip)) > 0) {
    header('Retry-After: ' . $wait);
    Http::json(['error' => 'Zu viele Fehlversuche. Bitte in ' . (int) ceil($wait / 60) . ' Minuten erneut versuchen.'], 429);
}

if (Http::path() === '/login/passkey/options') {
    try {
        Http::json(Mfa::passkeyLoginOptions());
    } catch (RuntimeException $e) {
        Http::json(['error' => $e->getMessage()], 429);
    }
}
$uid = Mfa::passkeyLogin((array) ($in['credential'] ?? []));
if ($uid === null) {
    Auth::recordFailure($ip);
    Http::json(['error' => 'Der Passkey konnte nicht bestätigt werden.'], 401);
}
Auth::clearFailures($ip);
Auth::startSession($uid);
Http::json(['ok' => true, 'redirect' => Http::url(Http::safeNext($in['next'] ?? ''))]);
