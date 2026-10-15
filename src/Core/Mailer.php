<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Minimaler Mail-Versand über die eingebaute PHP-Funktion mail() - auf
 * praktisch jedem Shared-Hosting-Paket ohne weitere Konfiguration lauffähig
 * (nutzt den lokalen MTA/Sendmail des Hosters). Bewusst ohne Composer-
 * Abhängigkeit, passend zur restlichen Codebasis; bei Bedarf 1:1 durch
 * z.B. PHPMailer mit SMTP ersetzbar, ohne den Rest der Anwendung
 * anzufassen (siehe composer.json "suggest").
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $fromAddress = Env::get('MAIL_FROM', 'no-reply@localhost');
        $fromName = Env::get('MAIL_FROM_NAME', 'Familienkalender');

        $headers = implode("\r\n", [
            'From: ' . self::encodeHeaderWord($fromName) . " <{$fromAddress}>",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        $encodedSubject = self::encodeHeaderWord($subject);

        return mail($to, $encodedSubject, $body, $headers);
    }

    /** RFC 2047 Encoded-Word, damit Umlaute in Betreff/Absendername korrekt ankommen. */
    private static function encodeHeaderWord(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
