<?php

declare(strict_types=1);

/**
 * Winziger SMTP-Server-Stub für SmtpMailerTest - spricht nur genug vom
 * Protokoll, um SmtpMailer vollständig zu durchlaufen (EHLO, optional
 * AUTH LOGIN, MAIL FROM/RCPT TO/DATA, QUIT), OHNE TLS (STARTTLS wird
 * bewusst nicht unterstützt - ein echtes TLS-Handshake-Gegenstück in einem
 * Test-Stub aufzusetzen lohnt den Aufwand nicht, SmtpMailer wird dafür
 * stattdessen mit encryption="none" getestet).
 *
 * Schreibt jede empfangene Zeile in FAKE_SMTP_TRANSCRIPT_FILE, damit der
 * Test nachträglich prüfen kann, was tatsächlich gesendet wurde - genau
 * das gleiche Muster wie tests/Support/fake-sendmail.sh für mail().
 *
 * Aufruf: php fake-smtp-server.php <port>
 */

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "Usage: php fake-smtp-server.php <port>\n");
    exit(1);
}

$transcriptFile = getenv('FAKE_SMTP_TRANSCRIPT_FILE') ?: '/tmp/fake_smtp_transcript.txt';
file_put_contents($transcriptFile, '');

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Konnte nicht auf Port {$port} lauschen: {$errstr}\n");
    exit(1);
}

// Signalisiert dem startenden Testprozess (FakeSmtpServer::start()), dass
// der Port jetzt wirklich gebunden ist - ein Verbindungsversuch von dort
// aus wäre riskant, da dieser Server nur EINE einzige Verbindung über
// stream_socket_accept() annimmt und ein "Nur-Testen-ob-erreichbar"-Connect
// genau diese eine Verbindung verbrauchen würde.
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$conn = stream_socket_accept($server, 30);
if ($conn === false) {
    exit(0);
}

function logLine(string $file, string $line): void
{
    file_put_contents($file, $line . "\n", FILE_APPEND);
}

function sendLine($conn, string $line): void
{
    fwrite($conn, $line . "\r\n");
}

sendLine($conn, '220 fake-smtp ready');

$inData = false;
$dataBuffer = '';

while (($line = fgets($conn, 4096)) !== false) {
    $trimmed = rtrim($line, "\r\n");

    if ($inData) {
        if ($trimmed === '.') {
            $inData = false;
            logLine($transcriptFile, 'DATA_BODY_START');
            logLine($transcriptFile, $dataBuffer);
            logLine($transcriptFile, 'DATA_BODY_END');
            sendLine($conn, '250 OK: message accepted');
            continue;
        }
        $dataBuffer .= $trimmed . "\n";
        continue;
    }

    logLine($transcriptFile, $trimmed);
    $command = strtoupper(explode(' ', $trimmed, 2)[0] ?? '');

    switch ($command) {
        case 'EHLO':
        case 'HELO':
            sendLine($conn, '250-fake-smtp greets you');
            sendLine($conn, '250 AUTH LOGIN');
            break;
        case 'AUTH':
            sendLine($conn, '334 VXNlcm5hbWU6'); // "Username:"
            break;
        case 'MAIL':
            sendLine($conn, '250 OK');
            break;
        case 'RCPT':
            sendLine($conn, '250 OK');
            break;
        case 'DATA':
            $inData = true;
            sendLine($conn, '354 Start mail input; end with <CRLF>.<CRLF>');
            break;
        case 'QUIT':
            sendLine($conn, '221 Bye');
            fclose($conn);
            fclose($server);
            exit(0);
        default:
            // Base64-kodierte AUTH-LOGIN-Antworten (Username/Passwort) landen
            // hier, da sie kein bekanntes SMTP-Verb sind.
            if (preg_match('/^VXNlcm5hbWU6$|^[A-Za-z0-9+\/=]+$/', $trimmed)) {
                static $authStep = 0;
                $authStep++;
                if ($authStep === 1) {
                    sendLine($conn, '334 UGFzc3dvcmQ6'); // "Password:"
                } else {
                    sendLine($conn, '235 Authentication successful');
                }
            } else {
                sendLine($conn, '500 unrecognized command');
            }
    }
}

fclose($conn);
fclose($server);
