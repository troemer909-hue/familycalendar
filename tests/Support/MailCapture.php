<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Support;

/**
 * Liest Mails, die Mailer::send() (PHP mail()) während Integration-Tests
 * "versendet" hat - der API-Testserver läuft mit sendmail_path auf
 * fake-sendmail.sh umgebogen (siehe ApiServer.php), das jede Mail an diese
 * Datei anhängt statt sie wirklich zu verschicken. Damit lassen sich
 * Passwort-Reset-/Einladungs-Codes aus echten End-to-End-Requests heraus
 * testen, ohne einen Mailserver zu brauchen.
 */
final class MailCapture
{
    public static function path(): string
    {
        return getenv('FAKE_MAIL_CAPTURE_FILE') ?: '/tmp/familienkalender_test_mail.eml';
    }

    public static function reset(): void
    {
        @unlink(self::path());
    }

    /** Extrahiert den 64-stelligen Hex-Token (Reset-/Einladungs-Code) aus der ZULETZT versendeten Mail. */
    public static function latestToken(): string
    {
        $path = self::path();
        if (!is_file($path)) {
            throw new \RuntimeException("Keine Mail eingefangen ({$path} existiert nicht) - wurde wirklich etwas versendet?");
        }

        $content = file_get_contents($path);
        $mails = array_filter(array_map('trim', explode('---END-OF-MAIL---', $content)));
        if (empty($mails)) {
            throw new \RuntimeException('Mail-Capture-Datei ist leer.');
        }

        $latest = end($mails);
        if (!preg_match('/\b([a-f0-9]{64})\b/', $latest, $matches)) {
            throw new \RuntimeException("Kein 64-stelliger Hex-Token in der zuletzt versendeten Mail gefunden:\n{$latest}");
        }

        return $matches[1];
    }
}
