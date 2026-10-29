<?php
declare(strict_types=1);

/** Texts of the mails the app sends, in German and English. */
final class MailTexts
{
    /**
     * @param array<string,string> $v link, app, hours (validity), new_email
     * @return array{0:string,1:string} subject and body
     */
    public static function build(string $kind, string $lang, array $v): array
    {
        $lang = $lang === 'en' ? 'en' : 'de';
        $app = $v['app'] ?? 'Startseite';
        $link = $v['link'] ?? '';
        $hours = $v['hours'] ?? '1';
        $t = [
            'verify' => [
                'de' => ["$app: Bitte E-Mail-Adresse bestätigen", "Hallo,\n\nfür diese Adresse wurde ein Konto bei $app angelegt. Zum Bestätigen und Aktivieren bitte diesen Link öffnen (gültig $hours Stunden):\n\n$link\n\nWenn du das nicht warst, kannst du diese Mail ignorieren. Es wird dann kein Konto angelegt."],
                'en' => ["$app: Please confirm your email address", "Hello,\n\nan account was requested at $app for this address. To confirm and activate it, open this link (valid for $hours hours):\n\n$link\n\nIf this was not you, ignore this email. No account will be created."],
            ],
            'reset' => [
                'de' => ["$app: Passwort zurücksetzen", "Hallo,\n\nfür dein Konto bei $app wurde ein neues Passwort angefordert. Zum Festlegen bitte diesen Link öffnen (gültig $hours Stunde, nur einmal benutzbar):\n\n$link\n\nWenn du das nicht angefordert hast, ignoriere diese Mail. Dein Passwort bleibt dann unverändert."],
                'en' => ["$app: Reset your password", "Hello,\n\na new password was requested for your account at $app. To set it, open this link (valid for $hours hour, single use):\n\n$link\n\nIf you did not request this, ignore this email. Your password stays unchanged."],
            ],
            'invite' => [
                'de' => ["$app: Dein Konto ist bereit", "Hallo,\n\nfür dich wurde ein Konto bei $app angelegt. Bitte lege über diesen Link dein Passwort fest (gültig $hours Stunden, nur einmal benutzbar):\n\n$link\n\nDanach meldest du dich mit dieser E-Mail-Adresse an."],
                'en' => ["$app: Your account is ready", "Hello,\n\nan account was created for you at $app. Please set your password with this link (valid for $hours hours, single use):\n\n$link\n\nAfterwards you sign in with this email address."],
            ],
            'email_change' => [
                'de' => ["$app: Neue E-Mail-Adresse bestätigen", "Hallo,\n\nfür ein Konto bei $app wurde diese Adresse als neue Anmelde-Adresse angegeben. Zum Bestätigen bitte diesen Link öffnen (gültig $hours Stunden):\n\n$link\n\nWenn du das nicht warst, ignoriere diese Mail."],
                'en' => ["$app: Confirm your new email address", "Hello,\n\nthis address was entered as the new sign-in address for an account at $app. To confirm, open this link (valid for $hours hours):\n\n$link\n\nIf this was not you, ignore this email."],
            ],
            'exists' => [
                'de' => ["$app: Für diese Adresse gibt es schon ein Konto", "Hallo,\n\nfür diese Adresse wurde eine Registrierung bei $app versucht, es gibt aber schon ein Konto. Falls du dein Passwort vergessen hast, kannst du es hier zurücksetzen:\n\n$link\n\nWenn du das nicht warst, ist nichts weiter zu tun."],
                'en' => ["$app: An account already exists for this address", "Hello,\n\nsomeone tried to register this address at $app, but an account already exists. If you forgot your password, you can reset it here:\n\n$link\n\nIf this was not you, nothing else is needed."],
            ],
            'test' => [
                'de' => ["$app: Testmail", "Diese Testmail bestätigt, dass der Mailversand von $app funktioniert."],
                'en' => ["$app: Test email", "This test email confirms that mail delivery from $app works."],
            ],
        ];
        return $t[$kind][$lang];
    }
}
