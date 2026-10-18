<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Interne Kontrollfluss-Exception für Response::json()/error()/ics() im
 * Capture-Modus (siehe Response::enableCapture()) - wird ausschließlich von
 * LocalApiClient geworfen und sofort wieder gefangen, nie nach außen
 * durchgereicht. Transportiert Status+Daten, damit ein In-Process-
 * Routenaufruf dieselbe Antwort liefert wie ein echter HTTP-Request, ohne
 * das PHP-Skript per exit() zu beenden (das würde sonst die aufrufende
 * Client-Seite mitbeenden statt nur den "inneren" API-Aufruf).
 */
final class ResponseCaptured extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $data
    ) {
        parent::__construct('Response captured (internes Kontrollfluss-Signal, kein echter Fehler).');
    }
}
