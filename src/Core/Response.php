<?php

declare(strict_types=1);

namespace Familienkalender\Core;

final class Response
{
    /**
     * Nur von LocalApiClient rund um einen In-Process-Routenaufruf gesetzt
     * (siehe dort) - lässt json()/error()/ics() eine ResponseCaptured-
     * Exception werfen statt echo+exit, damit der Aufruf im selben PHP-
     * Prozess weiterläuft statt die aufrufende Client-Seite mitzubeenden.
     * Bei einem echten HTTP-Request (public/api.php) bleibt dieser Wert
     * immer false - dort soll exit() wie gehabt das Skript beenden.
     */
    private static bool $captureMode = false;

    public static function enableCapture(): void
    {
        self::$captureMode = true;
    }

    public static function disableCapture(): void
    {
        self::$captureMode = false;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        if (self::$captureMode) {
            throw new ResponseCaptured($status, $data);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400): never
    {
        self::json(['error' => $message], $status);
    }

    /** Für den Outbound-ICS-Export (siehe IcsExportController::feed()). */
    public static function ics(string $body): never
    {
        if (self::$captureMode) {
            // Wird in der Praxis nie in-process aufgerufen (der Feed wird
            // Kalender-Apps per Link mit Geheimtoken in der URL gegeben,
            // nicht von einer eigenen Client-Seite nachgeladen) - aus
            // Konsistenz trotzdem wie json() behandelt statt ignoriert.
            throw new ResponseCaptured(200, ['ics' => $body]);
        }

        http_response_code(200);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="familienkalender.ics"');
        echo $body;
        exit;
    }
}
