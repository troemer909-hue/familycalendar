<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Support;

/**
 * Startet tests/Support/fake-smtp-server.php als eigenständigen Prozess auf
 * einem freien Port, damit SmtpMailerTest einen echten SMTP-Client gegen
 * einen echten (wenn auch winzigen) SMTP-Server laufen lassen kann - analog
 * zu ApiServer.php, das für Integration-Tests den PHP-Built-in-Server
 * startet.
 */
final class FakeSmtpServer
{
    /** @var resource|null */
    private $process = null;
    private int $port;
    private string $transcriptFile;

    public function start(): void
    {
        $this->port = random_int(20000, 60000);
        $this->transcriptFile = sys_get_temp_dir() . '/fake_smtp_transcript_' . getmypid() . '_' . $this->port . '.txt';

        $script = __DIR__ . '/fake-smtp-server.php';
        $cmd = sprintf('exec php %s %d', escapeshellarg($script), $this->port);

        $env = array_merge($_ENV, ['FAKE_SMTP_TRANSCRIPT_FILE' => $this->transcriptFile]);
        $this->process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);

        // Auf das "READY"-Signal des Servers warten, STATT selbst testweise
        // zu verbinden - der Server nimmt nur EINE einzige Verbindung an
        // (stream_socket_accept()), ein Test-Connect von hier aus würde
        // genau diese eine Verbindung verbrauchen, bevor der eigentliche
        // Test-Client (SmtpMailer) sie bekommt.
        stream_set_blocking($pipes[1], true);
        $readyLine = fgets($pipes[1]);
        if ($readyLine === false || !str_starts_with($readyLine, 'READY')) {
            throw new \RuntimeException('Fake-SMTP-Server ist nicht rechtzeitig gestartet.');
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    public function transcript(): string
    {
        // Kurze Nachlaufzeit: der Server schreibt die letzte Zeile, nachdem
        // der Client QUIT gesendet hat - minimal warten, falls der Prozess
        // noch nicht ganz fertig geschrieben hat.
        usleep(50_000);
        return is_file($this->transcriptFile) ? file_get_contents($this->transcriptFile) : '';
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (is_file($this->transcriptFile)) {
            @unlink($this->transcriptFile);
        }
    }
}
