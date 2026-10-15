<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Support;

/**
 * Startet public/api.php einmalig über den PHP-Built-in-Webserver, damit
 * Integration-Tests echte HTTP-Requests fahren können (Router, Middleware,
 * JSON-Serialisierung, HTTP-Statuscodes inklusive) - nicht nur die
 * Controller-Methoden direkt aufrufen, was ohnehin nicht ginge:
 * Response::json()/error() rufen exit() auf und würden den Testlauf selbst
 * beenden.
 *
 * `php -S ... api.php` leitet dabei JEDEN Request an api.php weiter, genau
 * wie die .htaccess-Rewrite-Regel es in Produktion tut (die der Built-in-
 * Server selbst nicht auswertet).
 *
 * Ein einzelner Serverprozess wird über den gesamten Testlauf hinweg
 * wiederverwendet (nicht pro Testklasse neu gestartet) und am Ende per
 * Shutdown-Function beendet - vermeidet Port-Konflikte, falls eine
 * Testklasse wegen eines Fehlers ihr eigenes tearDown überspringt.
 */
final class ApiServer
{
    private const HOST = '127.0.0.1';
    private const PORT = 8321;

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
            'exec php -S %s:%d -t %s %s',
            self::HOST,
            self::PORT,
            escapeshellarg($publicDir),
            escapeshellarg($publicDir . '/api.php')
        );

        $pipes = [];
        self::$process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (self::$process === false) {
            throw new \RuntimeException('API-Testserver konnte nicht gestartet werden.');
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
        $url = self::baseUrlWithoutStarting() . '/api/health';

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

        throw new \RuntimeException('API-Testserver wurde nicht innerhalb von 5 Sekunden bereit.');
    }

    private static function baseUrlWithoutStarting(): string
    {
        return 'http://' . self::HOST . ':' . self::PORT;
    }
}
