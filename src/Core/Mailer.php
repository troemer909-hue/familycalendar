<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Mail-Versand entweder über die eingebaute PHP-Funktion mail() (Standard,
 * nutzt den lokalen MTA/Sendmail des Hosters - auf praktisch jedem
 * Shared-Hosting-Paket ohne weitere Konfiguration lauffähig) ODER über
 * einen externen SMTP-Server (MAIL_DRIVER=smtp in der .env), für Hosts
 * ohne funktionierenden lokalen MTA. Bewusst ohne Composer-Abhängigkeit
 * (kein PHPMailer o.ä.), passend zur restlichen Codebasis - siehe
 * SmtpMailer für den eigentlichen SMTP-Client.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $fromAddress = Env::get('MAIL_FROM', 'no-reply@localhost');
        $fromName = Env::get('MAIL_FROM_NAME', 'Familienkalender');

        if (strtolower((string) Env::get('MAIL_DRIVER', 'mail')) === 'smtp') {
            return self::sendViaSmtp($to, $fromAddress, $fromName, $subject, $body);
        }

        $headers = implode("\r\n", [
            'From: ' . self::encodeHeaderWord($fromName) . " <{$fromAddress}>",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);

        $encodedSubject = self::encodeHeaderWord($subject);

        return mail($to, $encodedSubject, $body, $headers);
    }

    private static function sendViaSmtp(string $to, string $from, string $fromName, string $subject, string $body): bool
    {
        $host = Env::get('SMTP_HOST');
        if (!$host) {
            error_log('MAIL_DRIVER=smtp gesetzt, aber SMTP_HOST fehlt in der .env.');
            return false;
        }

        $encryption = strtolower((string) Env::get('SMTP_ENCRYPTION', 'tls'));
        $defaultPort = $encryption === 'ssl' ? 465 : 587;
        $port = (int) Env::get('SMTP_PORT', (string) $defaultPort);

        $mailer = new SmtpMailer(
            host: $host,
            port: $port,
            encryption: in_array($encryption, ['tls', 'ssl'], true) ? $encryption : 'none',
            username: Env::get('SMTP_USERNAME'),
            password: Env::get('SMTP_PASSWORD'),
        );

        try {
            $mailer->send($to, $from, $fromName, $subject, $body);
            return true;
        } catch (\RuntimeException $e) {
            error_log('SMTP-Mailversand fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    /** RFC 2047 Encoded-Word, damit Umlaute in Betreff/Absendername korrekt ankommen. */
    private static function encodeHeaderWord(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
