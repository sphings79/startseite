<?php
declare(strict_types=1);

/**
 * Public account pages without a session: /register, /verify, /forgot, /reset, /verify-email.
 * Texts are German and English; the language follows ?lang= or the browser.
 *
 * @var string $path
 */
$lang = in_array($_GET['lang'] ?? $_POST['lang_page'] ?? '', ['de', 'en'], true) ? ($_GET['lang'] ?? $_POST['lang_page']) : Accounts::guessLang();
$L = [
    'de' => [
        'register' => 'Registrieren', 'forgot' => 'Passwort vergessen', 'reset' => 'Neues Passwort festlegen', 'email' => 'E-Mail-Adresse',
        'password' => 'Passwort', 'password2' => 'Passwort wiederholen', 'newpass' => 'Neues Passwort', 'newpass2' => 'Neues Passwort wiederholen',
        'hint' => 'Mindestens 12 Zeichen mit Buchstaben, einer Zahl und einem Sonderzeichen.', 'mailnote' => 'Mails in der Sprache:',
        'send' => 'Absenden', 'save' => 'Passwort speichern', 'to_login' => 'Zur Anmeldung', 'have' => 'Schon ein Konto? Anmelden',
        'mismatch' => 'Die Passwörter stimmen nicht überein.', 'stale' => 'Die Seite war zu lange offen. Bitte erneut versuchen.',
        'reg_sent' => 'Wenn diese Adresse zugelassen ist, haben wir eine Mail mit einem Bestätigungslink geschickt. Bitte auch den Spam-Ordner prüfen.',
        'forgot_sent' => 'Wenn es ein Konto mit dieser Adresse gibt, haben wir eine Mail mit einem Link zum Zurücksetzen geschickt.',
        'forgot_text' => 'Gib deine E-Mail-Adresse ein. Du bekommst einen Link zum Festlegen eines neuen Passworts.',
        'verified' => 'Die Adresse ist bestätigt. Du kannst dich jetzt anmelden.', 'bad_link' => 'Der Link ist ungültig oder abgelaufen.',
        'reset_done' => 'Das Passwort wurde geändert. Alle Sitzungen wurden beendet, bitte neu anmelden.', 'email_done' => 'Die neue Adresse ist bestätigt. Bitte mit ihr neu anmelden.',
        'lang_de' => 'Deutsch', 'lang_en' => 'English', 'mail_fail' => 'Die Mail konnte nicht gesendet werden.',
    ],
    'en' => [
        'register' => 'Sign up', 'forgot' => 'Forgot password', 'reset' => 'Choose a new password', 'email' => 'Email address',
        'password' => 'Password', 'password2' => 'Repeat password', 'newpass' => 'New password', 'newpass2' => 'Repeat new password',
        'hint' => 'At least 12 characters with letters, a digit and a special character.', 'mailnote' => 'Language of emails:',
        'send' => 'Submit', 'save' => 'Save password', 'to_login' => 'Go to sign-in', 'have' => 'Already have an account? Sign in',
        'mismatch' => 'The passwords do not match.', 'stale' => 'The page was open too long. Please try again.',
        'reg_sent' => 'If this address is allowed, we sent an email with a confirmation link. Please check your spam folder too.',
        'forgot_sent' => 'If an account exists for this address, we sent an email with a reset link.',
        'forgot_text' => 'Enter your email address. You will get a link to set a new password.',
        'verified' => 'Your address is confirmed. You can sign in now.', 'bad_link' => 'The link is invalid or has expired.',
        'reset_done' => 'Your password was changed. All sessions were ended, please sign in again.', 'email_done' => 'Your new address is confirmed. Please sign in with it.',
        'lang_de' => 'Deutsch', 'lang_en' => 'English', 'mail_fail' => 'The email could not be sent.',
    ],
][$lang];

$notice = '';   // success message
$error = '';
$form = null;   // which form to show: register | forgot | reset
$token = '';
$email = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$formToken = Auth::loginToken();

if ($isPost) {
    Http::assertSameOrigin();
    if (!Auth::checkLoginToken((string) ($_POST['form'] ?? ''))) {
        $error = $L['stale'];
    }
}

try {
    switch ($path) {
        case '/register':
            $form = 'register';
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($isPost && $error === '') {
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    $error = $L['mismatch'];
                } else {
                    Accounts::register($email, (string) ($_POST['password'] ?? ''), (string) ($_POST['mail_lang'] ?? $lang), Http::clientIp());
                    $notice = $L['reg_sent'];
                    $form = null;
                }
            }
            break;
        case '/forgot':
            $form = 'forgot';
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($isPost && $error === '') {
                Accounts::requestReset($email, Http::clientIp());
                $notice = $L['forgot_sent'];
                $form = null;
            }
            break;
        case '/reset':
            $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
            $form = 'reset';
            if (Accounts::peekToken($token, 'reset') === null && !($isPost && $notice !== '')) {
                $error = $L['bad_link'];
                $form = null;
            } elseif ($isPost && $error === '') {
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    $error = $L['mismatch'];
                } else {
                    Accounts::reset($token, (string) ($_POST['password'] ?? ''));
                    $notice = $L['reset_done'];
                    $form = null;
                }
            }
            break;
        case '/verify':
            $notice = Accounts::verify((string) ($_GET['token'] ?? '')) ? $L['verified'] : '';
            $error = $notice === '' ? $L['bad_link'] : '';
            break;
        case '/verify-email':
            $notice = Accounts::confirmEmailChange((string) ($_GET['token'] ?? '')) ? $L['email_done'] : '';
            $error = $notice === '' ? $L['bad_link'] : '';
            break;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    $error = $e->getMessage();
} catch (RuntimeException $e) {
    http_response_code(502);
    $error = $L['mail_fail'] . ' ' . $e->getMessage();
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer'); // the token in the address must not leak to other sites
$appName = (string) Settings::get('app_name');
$regOpen = AppConfig::get('reg_mode') !== 'off';
require APP_ROOT . '/src/views/account.php';
