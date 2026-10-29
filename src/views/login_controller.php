<?php
declare(strict_types=1);

$error = '';
$username = '';
$mfa = null; // second step: ['ticket' => string, 'methods' => array]
$next = Http::safeNext($_POST['next'] ?? $_GET['next'] ?? '');

if (Auth::current() !== null) {
    Http::redirect($next);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Http::assertSameOrigin();
    if (isset($_POST['ticket'])) {
        // Second step: password was accepted, now the authenticator code or a recovery code.
        $ticket = (string) $_POST['ticket'];
        $uid = Mfa::ticketUser($ticket);
        if ($uid === null) {
            $error = 'Die Anmeldung ist abgelaufen. Bitte erneut anmelden.';
        } else {
            $mfa = ['ticket' => $ticket, 'methods' => Mfa::methods($uid)];
            if (($wait = Mfa::lockedFor($uid)) > 0) {
                http_response_code(429);
                header('Retry-After: ' . $wait);
                $error = 'Zu viele Fehlversuche. Bitte in ' . (int) ceil($wait / 60) . ' Minuten erneut versuchen.';
            } elseif (Mfa::verifyCode($uid, (string) ($_POST['code'] ?? ''))) {
                Auth::startSession($uid);
                Http::redirect($next);
            } else {
                http_response_code(401);
                $error = 'Der Code ist ungültig.';
            }
        }
    } else {
        $username = (string) ($_POST['username'] ?? '');
        if (!Auth::checkLoginToken((string) ($_POST['token'] ?? ''))) {
            $error = 'Die Seite war zu lange offen. Bitte erneut versuchen.';
        } elseif (Auth::userCount() === 0) {
            $error = 'Es ist noch kein Benutzer eingerichtet.';
        } else {
            $res = Auth::attemptLogin($username, (string) ($_POST['password'] ?? ''), Http::clientIp());
            if ($res['ok']) {
                Http::redirect($next);
            }
            if (isset($res['mfa'])) {
                $mfa = ['ticket' => $res['mfa'], 'methods' => Mfa::methods((int) Mfa::ticketUser($res['mfa']))];
            } elseif ($res['retry_after'] > 0) {
                http_response_code(429);
                header('Retry-After: ' . $res['retry_after']);
                $error = 'Zu viele Fehlversuche. Bitte in ' . (int) ceil($res['retry_after'] / 60) . ' Minuten erneut versuchen.';
            } else {
                http_response_code(401);
                $error = 'Benutzername oder Passwort falsch.';
            }
        }
    }
}

header('Cache-Control: no-store');
$token = Auth::loginToken();
$appName = (string) Settings::get('app_name');
$noUser = Auth::userCount() === 0;
require APP_ROOT . '/src/views/' . ($mfa !== null ? 'login_mfa.php' : 'login.php');
