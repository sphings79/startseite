<?php
declare(strict_types=1);

/** The SMTP client against a fake server: plain without login, with login, STARTTLS and implicit TLS. Needs STARTSEITE_DATA_DIR. */
require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$dir = (string) getenv('STARTSEITE_DATA_DIR');
$cert = selfSignedCert($dir);
$servers = [];
$cfg = static function (string $mode, int $port, array $extra = []): void {
    AppConfig::update($extra + ['smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_security' => $mode === 'ssl' ? 'ssl' : ($mode === 'starttls' ? 'starttls' : 'none'),
        'smtp_user' => '', 'smtp_pass_clear' => !isset($extra['smtp_pass']), 'smtp_from' => 'startseite@example.org', 'smtp_from_name' => 'Startseite Ä', 'smtp_verify' => false]);
};

T::section('SMTP client');
[$p1, $port1, $f1] = startSmtp('plain', $dir);
$servers[] = $p1;
$cfg('plain', $port1);
T::check('mail is considered configured', Mail::configured());
Mail::send('anna@example.org', 'Grüße ✓', "Hallo\n.\nPunkt am Zeilenanfang und Umlaute: äöü");
$m = readMails($f1);
T::check('plain server without login and encryption accepts the mail', count($m) === 1 && $m[0]['tls'] === false && $m[0]['auth'] === false);
T::check('recipient and sender are sent', str_contains(implode(' ', $m[0]['to']), 'anna@example.org') && str_contains($m[0]['from'], 'startseite@example.org'));
T::check('body survives dots and umlauts', str_contains($m[0]['body'], "\n.\nPunkt am Zeilenanfang und Umlaute: äöü"));
T::check('subject with non-ASCII is encoded', str_contains($m[0]['subject_raw'], '=?UTF-8?B?'));
T::throws('header injection in the address is refused', fn () => Mail::send("a@example.org\r\nBcc: x@example.org", 's', 'b'), RuntimeException::class);

[$p2, $port2, $f2] = startSmtp('auth', $dir);
$servers[] = $p2;
$cfg('plain', $port2, ['smtp_user' => 'mailuser', 'smtp_pass' => 'mailpass']);
Mail::send('anna@example.org', 'Login', 'mit Anmeldung');
$m = readMails($f2);
T::check('AUTH works and the password is stored sealed', count($m) === 1 && $m[0]['auth'] === true && AppConfig::all()['smtp_pass'] !== 'mailpass');
$cfg('plain', $port2, ['smtp_user' => 'mailuser', 'smtp_pass' => 'wrong']);
T::throws('wrong password gives a readable error without the password', function () { try { Mail::send('anna@example.org', 's', 'b'); } catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'wrong')) { throw new LogicException('password leaked'); } throw $e; } }, RuntimeException::class);

[$p3, $port3, $f3] = startSmtp('starttls', $dir, $cert);
$servers[] = $p3;
$cfg('starttls', $port3, ['smtp_user' => 'mailuser', 'smtp_pass' => 'mailpass']);
Mail::send('anna@example.org', 'STARTTLS', 'verschlüsselt');
$m = readMails($f3);
T::check('STARTTLS upgrades the connection before login', count($m) === 1 && $m[0]['tls'] === true && $m[0]['auth'] === true);

[$p4, $port4, $f4] = startSmtp('ssl', $dir, $cert);
$servers[] = $p4;
$cfg('ssl', $port4, ['smtp_user' => 'mailuser', 'smtp_pass' => 'mailpass']);
Mail::send('anna@example.org', 'SSL', 'implizit');
$m = readMails($f4);
T::check('implicit TLS works', count($m) === 1 && $m[0]['tls'] === true);
AppConfig::update(['smtp_verify' => true]);
T::throws('a self-signed certificate is refused while verification is on', fn () => Mail::send('anna@example.org', 's', 'b'), RuntimeException::class);

T::section('Registration policy');
AppConfig::update(['reg_mode' => 'domains', 'reg_allowed' => "Example.org\n# comment\nbob@other.test\n@third.test"]);
T::check('domain on the list is allowed', AppConfig::registrationAllows('x@example.org'));
T::check('single address on the list is allowed', AppConfig::registrationAllows('bob@other.test'));
T::check('other address of that domain is not', !AppConfig::registrationAllows('eve@other.test'));
T::check('domain with @ is accepted in the list', AppConfig::registrationAllows('y@third.test'));
T::check('sub-domain is not covered by the domain', !AppConfig::registrationAllows('x@mail.example.org'));
T::check('lookalike domain is refused', !AppConfig::registrationAllows('x@example.org.evil.test') && !AppConfig::registrationAllows('x@notexample.org'));
AppConfig::update(['reg_mode' => 'off']);
T::check('registration off allows nobody', !AppConfig::registrationAllows('x@example.org'));
AppConfig::update(['reg_mode' => 'any']);
T::check('"any" allows everyone', AppConfig::registrationAllows('x@whatever.test'));
T::throws('a bad entry in the list is refused', fn () => AppConfig::update(['reg_allowed' => 'not a domain!']), InvalidArgumentException::class);

foreach ($servers as $p) {
    proc_terminate($p);
}
echo "\n";
exit(T::$fail > 0 ? 1 : 0);
