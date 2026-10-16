<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Support;

/**
 * Startet public/ ein zweites Mal über den PHP-Built-in-Webserver - OHNE
 * Catch-all-Skript, also genau wie ein echter Webserver im Shared-Hosting
 * jede *.php-Datei direkt ausführt. Nötig, um die session-basierten
 * Client-Seiten (calendar.php, event_form.php, ...) end-to-end zu testen:
 * die rufen selbst wieder die API auf (siehe ApiClient), und das darf NICHT
 * derselbe Serverprozess sein wie ApiServer - ein `php -S`-Prozess
 * bearbeitet nur einen Request gleichzeitig, ein ausgehender Call auf sich
 * selbst würde also blockieren, bis er in Timeout läuft.
 */
final class FrontendServer
{
    private const HOST = '127.0.0.1';
    private const PORT = 8322;

    /** @var resource|null */
    private static $process = null;
    private static bool $shutdownRegistered = false;

    public static function baseUrl(): string
    {
        self::ensureStarted();
        return 'http://' . self::HOST . ':' . self::PORT;
    }

    private static function ensureStarted(): void
    {
        if (self::$process !== null) {
            return;
        }

        $publicDir = dirname(__DIR__, 2) . '/public';
        $cmd = sprintf(
            'exec php -S %s:%d -t %s',
            self::HOST,
            self::PORT,
            escapeshellarg($publicDir)
        );

        $pipes = [];
        self::$process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (self::$process === false) {
            throw new \RuntimeException('Frontend-Testserver konnte nicht gestartet werden.');
        }

        if (!self::$shutdownRegistered) {
            register_shutdown_function(static function (): void {
                if (self::$process !== null) {
                    proc_terminate(self::$process);
                    proc_close(self::$process);
                }
            });
            self::$shutdownRegistered = true;
        }

        self::waitUntilReady();
    }

    private static function waitUntilReady(): void
    {
        $deadline = microtime(true) + 5.0;
        $url = self::baseUrlWithoutStarting() . '/login.php';

        while (microtime(true) < $deadline) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 300]);
            $result = curl_exec($ch);
            curl_close($ch);

            if ($result !== false) {
                return;
            }
            usleep(100_000);
        }

        throw new \RuntimeException('Frontend-Testserver wurde nicht innerhalb von 5 Sekunden bereit.');
    }

    private static function baseUrlWithoutStarting(): string
    {
        return 'http://' . self::HOST . ':' . self::PORT;
    }
}
