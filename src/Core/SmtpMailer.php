<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Minimaler SMTP-Client für den Mail-Versand über einen externen Mailserver
 * (statt der lokalen PHP mail()-Funktion/des lokalen MTA) - bewusst ohne
 * Composer-Abhängigkeit (kein PHPMailer/Symfony Mailer o.ä.), passend zur
 * restlichen Codebasis, nur die eingebaute Socket-Funktionalität.
 *
 * Wird genutzt, wenn MAIL_DRIVER=smtp in der .env gesetzt ist (siehe
 * Mailer::send()) - Standard bleibt weiterhin die lokale mail()-Funktion,
 * für Hosts ohne funktionierenden lokalen MTA (z.B. shared Hosting ohne
 * konfiguriertes Sendmail/Postfix) ist SMTP gegen einen externen Anbieter
 * (z.B. den Mailserver des eigenen Providers, SendGrid, Mailgun, ...) oft
 * die einzige zuverlässige Option.
 *
 * Unterstützt STARTTLS (Port 587, Standard) und implizites TLS/SSL
 * (Port 465), sowie AUTH LOGIN (Benutzername/Passwort per Base64, wie von
 * praktisch jedem SMTP-Anbieter erwartet).
 */
final class SmtpMailer
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // 'tls' (STARTTLS), 'ssl' (implizit), 'none'
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * @throws \RuntimeException bei jedem Protokoll-/Verbindungsfehler -
     *   der Aufrufer (Mailer::send()) fängt das ab und meldet nur false,
     *   damit ein fehlgeschlagener Mailversand nie eine Seite zum Absturz
     *   bringt (z.B. beim Einladen eines Familienmitglieds).
     */
    public function send(string $to, string $from, string $fromName, string $subject, string $body): void
    {
        $this->connect();

        try {
            $this->expect(220);
            $localHost = Env::get('SMTP_EHLO_HOST') ?: (gethostname() ?: 'localhost');

            $this->command("EHLO {$localHost}", 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                $this->enableCrypto();
                // Nach STARTTLS verlangt der Server laut RFC 3207 ein
                // erneutes EHLO auf der jetzt verschlüsselten Verbindung.
                $this->command("EHLO {$localHost}", 250);
            }

            if ($this->username !== null && $this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode((string) $this->password), 235);
            }

            $this->command('MAIL FROM:<' . $from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250);
            $this->command('DATA', 354);

            $headers = implode("\r\n", [
                'From: ' . self::encodeHeaderWord($fromName) . " <{$from}>",
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeaderWord($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $localHost . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);

            // Dot-Stuffing (RFC 5321): eine Zeile, die mit "." beginnt, muss
            // durch einen zweiten führenden Punkt escaped werden, sonst
            // interpretiert der Server sie als Ende der DATA-Sektion.
            $escapedBody = preg_replace('/^\./m', '..', $body);

            $this->write($headers . "\r\n\r\n" . $escapedBody . "\r\n.\r\n");
            $this->expect(250);

            $this->command('QUIT', 221);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $transport = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "{$transport}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException("SMTP-Verbindung zu {$this->host}:{$this->port} fehlgeschlagen: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function enableCrypto(): void
    {
        $result = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($result !== true) {
            throw new \RuntimeException('STARTTLS fehlgeschlagen (TLS-Handshake nicht erfolgreich).');
        }
    }

    private function command(string $line, int $expectedCode): void
    {
        $this->write($line . "\r\n");
        $this->expect($expectedCode);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('Schreiben auf die SMTP-Verbindung fehlgeschlagen.');
        }
    }

    /** Liest eine (ggf. mehrzeilige) SMTP-Antwort und prüft den Statuscode. */
    private function expect(int $expectedCode): string
    {
        $response = '';
        do {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                $reason = !empty($meta['timed_out']) ? 'Zeitüberschreitung' : 'Verbindung unterbrochen';
                throw new \RuntimeException("SMTP-Antwort konnte nicht gelesen werden ({$reason}).");
            }
            $response .= $line;
            // Mehrzeilige Antworten haben einen Bindestrich nach dem
            // Statuscode ("250-..."), die letzte Zeile ein Leerzeichen
            // ("250 ...") - siehe RFC 5321 Abschnitt 4.2.1.
            $isLastLine = isset($line[3]) && $line[3] === ' ';
        } while (!$isLastLine);

        $actualCode = (int) substr($response, 0, 3);
        if ($actualCode !== $expectedCode) {
            throw new \RuntimeException("Unerwartete SMTP-Antwort (erwartet {$expectedCode}): " . trim($response));
        }

        return $response;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /** RFC 2047 Encoded-Word, damit Umlaute in Betreff/Absendername korrekt ankommen. */
    private static function encodeHeaderWord(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
