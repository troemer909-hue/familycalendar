<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Minimaler, selbst geschriebener ICS-Exporter (RFC 5545) - das Gegenstück
 * zu IcsParser, bewusst ohne Composer-Abhängigkeit. Erzeugt einen
 * VCALENDAR-Feed, den externe Clients (Google/Outlook/Apple Kalender)
 * abonnieren können (siehe IcsExportController).
 *
 * Bewusst vereinfacht, passend zur restlichen Codebasis: keine
 * Zeitzonen-Konvertierung (Zeiten werden als "floating" lokale Zeit
 * ausgegeben, siehe IcsParser), und die 75-Octet-Zeilenumbruchregel
 * (fold()) bricht byte-basiert um - bei sehr langen Beschreibungen mit
 * Mehrbyte-Zeichen (Umlaute etc.) könnte das rein theoretisch mitten in
 * einem UTF-8-Zeichen umbrechen. Für die typischen kurzen Titel/
 * Beschreibungen eines Familienkalenders in der Praxis kein Problem.
 */
final class IcsExporter
{
    /**
     * @param array<int, array{id:int,title:string,description:?string,
     *                          location:?string,starts_at:string,ends_at:string,all_day:bool}> $events
     */
    public static function build(string $calendarName, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Familienkalender//Export 1.0//DE',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:' . self::escape($calendarName),
        ];

        foreach ($events as $event) {
            array_push($lines, ...self::buildEvent($event));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** @return string[] */
    private static function buildEvent(array $event): array
    {
        // Stabile UID über Regenerierungen des Exports hinweg (abgeleitet
        // von der Termin-ID, nicht vom Token) - Kalender-Apps erkennen so
        // bei jedem Sync denselben Termin wieder, statt ihn zu duplizieren.
        $uid = 'event-' . $event['id'] . '@familienkalender';
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $stamp,
        ];

        if ($event['all_day']) {
            $start = new \DateTimeImmutable(substr($event['starts_at'], 0, 10));
            // DB speichert das Enddatum INKLUSIV (23:59:59 desselben Tages) -
            // ICS erwartet DTEND EXKLUSIV (erster Tag NACH dem Termin), siehe
            // IcsParser::buildEvent() für die umgekehrte Umrechnung beim Import.
            $end = (new \DateTimeImmutable(substr($event['ends_at'], 0, 10)))->modify('+1 day');
            $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . (new \DateTimeImmutable($event['starts_at']))->format('Ymd\THis');
            $lines[] = 'DTEND:' . (new \DateTimeImmutable($event['ends_at']))->format('Ymd\THis');
        }

        $lines[] = 'SUMMARY:' . self::escape($event['title']);
        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . self::escape($event['location']);
        }
        if (!empty($event['description'])) {
            $lines[] = 'DESCRIPTION:' . self::escape($event['description']);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** RFC 5545 Text-Escaping - Gegenstück zu IcsParser::unescape(). */
    private static function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([',', ';'], ['\\,', '\\;'], $value);
        return str_replace(["\r\n", "\n", "\r"], '\\n', $value);
    }

    /** RFC 5545: Zeilen >75 Octets umbrechen, Folgezeilen mit einem Leerzeichen einleiten. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [substr($line, 0, 75)];
        $rest = substr($line, 75);
        while ($rest !== '') {
            $chunks[] = ' ' . substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }

        return implode("\r\n", $chunks);
    }
}
