<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Minimaler, selbst geschriebener ICS-Parser (RFC 5545, nur die Teile, die
 * für den Familienkalender-Import gebraucht werden) - bewusst ohne
 * Composer-Abhängigkeit, passend zur restlichen Codebasis (vgl.
 * composer.json "suggest": johngrogg/ics-parser als Alternative, falls
 * später mehr Spezialfälle abgedeckt werden müssen).
 *
 * Unterstützt: VEVENT mit UID, SUMMARY, DESCRIPTION, LOCATION, DTSTART,
 * DTEND/DURATION (inkl. VALUE=DATE für ganztägige Termine). Zeitzonen
 * (TZID, "Z"-Suffix) werden NICHT umgerechnet, sondern als lokale Zeit
 * übernommen - für den typischen Fall "eine Familie, eine Zeitzone"
 * ausreichend.
 */
final class IcsParser
{
    /**
     * @return array<int, array{uid:string,title:string,description:?string,
     *                           location:?string,starts_at:string,ends_at:string,all_day:bool}>
     */
    public static function parse(string $ics): array
    {
        $lines = self::unfold($ics);

        $events = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }

            if (strcasecmp($line, 'BEGIN:VEVENT') === 0) {
                $current = [];
                continue;
            }
            if (strcasecmp($line, 'END:VEVENT') === 0) {
                if ($current !== null) {
                    $event = self::buildEvent($current);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue; // außerhalb eines VEVENT-Blocks (VCALENDAR-Header, VTIMEZONE, ...)
            }

            [$name, $params, $value] = self::splitProperty($line);
            $current[$name] = ['params' => $params, 'value' => $value];
        }

        if (empty($events)) {
            throw new \RuntimeException('Keine Termine (VEVENT) in der Datei gefunden.');
        }

        return $events;
    }

    /** @return string[] */
    private static function unfold(string $ics): array
    {
        $raw = preg_split("/\r\n|\n|\r/", $ics) ?: [];
        $lines = [];
        foreach ($raw as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && !empty($lines)) {
                $lines[count($lines) - 1] .= substr($line, 1);
            } else {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /** @return array{0:string,1:array<string,string>,2:string} */
    private static function splitProperty(string $line): array
    {
        $colonPos = strpos($line, ':');
        if ($colonPos === false) {
            return [strtoupper($line), [], ''];
        }

        $head = substr($line, 0, $colonPos);
        $value = substr($line, $colonPos + 1);

        $parts = explode(';', $head);
        $name = strtoupper($parts[0]);

        $params = [];
        for ($i = 1; $i < count($parts); $i++) {
            [$key, $paramValue] = array_pad(explode('=', $parts[$i], 2), 2, '');
            $params[strtoupper($key)] = $paramValue;
        }

        return [$name, $params, $value];
    }

    /**
     * @param array<string, array{params: array<string,string>, value:string}> $props
     */
    private static function buildEvent(array $props): ?array
    {
        if (!isset($props['DTSTART'])) {
            return null; // ohne Startzeit kein brauchbarer Termin
        }

        $isAllDay = ($props['DTSTART']['params']['VALUE'] ?? '') === 'DATE';

        $starts = self::parseDateTime($props['DTSTART']['value'], $isAllDay);
        if (isset($props['DTEND'])) {
            $ends = self::parseDateTime($props['DTEND']['value'], $isAllDay);
        } elseif (isset($props['DURATION'])) {
            $ends = self::addDuration($starts, $props['DURATION']['value']);
        } else {
            $ends = $starts;
        }

        if ($isAllDay) {
            // ICS: DTEND ist bei ganztägigen Terminen exklusiv (erster Tag
            // NACH dem Termin) - für die Anzeige im Kalender einen Tag
            // zurückrechnen, damit "bis" inklusiv ist.
            $endDate = (new \DateTimeImmutable($ends))->modify('-1 day');
            $startDate = new \DateTimeImmutable($starts);
            if ($endDate < $startDate) {
                $endDate = $startDate;
            }
            $ends = $endDate->format('Y-m-d') . ' 23:59:59';
            $starts = $startDate->format('Y-m-d') . ' 00:00:00';
        }

        $uid = $props['UID']['value'] ?? '';
        if ($uid === '') {
            $uid = md5(($props['SUMMARY']['value'] ?? '') . $starts . $ends);
        }

        return [
            'uid' => substr($uid, 0, 255),
            'title' => self::unescape($props['SUMMARY']['value'] ?? ''),
            'description' => isset($props['DESCRIPTION']) ? self::unescape($props['DESCRIPTION']['value']) : null,
            'location' => isset($props['LOCATION']) ? self::unescape($props['LOCATION']['value']) : null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'all_day' => $isAllDay,
        ];
    }

    private static function parseDateTime(string $value, bool $isAllDay): string
    {
        $value = rtrim($value, 'Z'); // Zeitzonen-Suffix ignorieren (siehe Klassendoc)

        if ($isAllDay || strlen($value) === 8) {
            $date = \DateTimeImmutable::createFromFormat('Ymd', substr($value, 0, 8));
            if (!$date) {
                throw new \RuntimeException("Ungültiges Datum im ICS: {$value}");
            }
            return $date->format('Y-m-d') . ' 00:00:00';
        }

        $date = \DateTimeImmutable::createFromFormat('Ymd\THis', $value);
        if (!$date) {
            throw new \RuntimeException("Ungültige Datumszeit im ICS: {$value}");
        }
        return $date->format('Y-m-d H:i:s');
    }

    private static function addDuration(string $start, string $duration): string
    {
        try {
            $interval = new \DateInterval($duration);
            return (new \DateTimeImmutable($start))->add($interval)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $start;
        }
    }

    private static function unescape(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    }
}
