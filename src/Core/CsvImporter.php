<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Minimaler CSV-Importer für Termine. Erwartet eine Kopfzeile; Spalten
 * "title"/"titel", "starts_at"/"beginn", "ends_at"/"ende" sind Pflicht,
 * der Rest optional. Trennzeichen (";" oder ",") wird automatisch erkannt,
 * Zeilen mit fehlenden Pflichtfeldern werden übersprungen statt den
 * gesamten Import abzubrechen.
 */
final class CsvImporter
{
    private const ALIASES = [
        'title' => ['title', 'titel', 'name'],
        'starts_at' => ['starts_at', 'beginn', 'start', 'von'],
        'ends_at' => ['ends_at', 'ende', 'end', 'bis'],
        'description' => ['description', 'beschreibung'],
        'location' => ['location', 'ort'],
        'all_day' => ['all_day', 'ganztägig', 'ganztaegig'],
    ];

    /**
     * @return array<int, array{uid:string,title:string,description:?string,
     *                           location:?string,starts_at:string,ends_at:string,all_day:bool}>
     */
    public static function parse(string $csv): array
    {
        $delimiter = substr_count($csv, ';') > substr_count($csv, ',') ? ';' : ',';

        $rows = array_values(array_filter(
            preg_split("/\r\n|\n|\r/", trim($csv)) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));

        if (count($rows) < 2) {
            throw new \RuntimeException('CSV benötigt eine Kopfzeile und mindestens eine Datenzeile.');
        }

        $header = array_map(
            static fn (string $h): string => strtolower(trim($h)),
            str_getcsv($rows[0], $delimiter)
        );
        $columns = self::mapColumns($header);

        if (!isset($columns['title'], $columns['starts_at'], $columns['ends_at'])) {
            throw new \RuntimeException('CSV benötigt mindestens die Spalten Titel, Beginn und Ende.');
        }

        $events = [];
        for ($i = 1; $i < count($rows); $i++) {
            $cols = str_getcsv($rows[$i], $delimiter);
            $get = static fn (string $key): string => trim((string) ($cols[$columns[$key] ?? -1] ?? ''));

            $title = $get('title');
            $startsRaw = $get('starts_at');
            $endsRaw = $get('ends_at');
            if ($title === '' || $startsRaw === '' || $endsRaw === '') {
                continue; // unvollständige Zeile überspringen
            }

            $events[] = [
                'uid' => 'csv-' . md5($title . $startsRaw . $endsRaw . $i),
                'title' => $title,
                'description' => isset($columns['description']) ? ($get('description') ?: null) : null,
                'location' => isset($columns['location']) ? ($get('location') ?: null) : null,
                'starts_at' => self::parseDate($startsRaw),
                'ends_at' => self::parseDate($endsRaw),
                'all_day' => isset($columns['all_day'])
                    && in_array(strtolower($get('all_day')), ['1', 'true', 'ja', 'yes'], true),
            ];
        }

        if (empty($events)) {
            throw new \RuntimeException('Keine gültigen Terminzeilen in der CSV gefunden.');
        }

        return $events;
    }

    /** @return array<string,int> kanonischer Spaltenname -> Index in der Zeile */
    private static function mapColumns(array $header): array
    {
        $columns = [];
        foreach (self::ALIASES as $canonical => $aliases) {
            foreach ($header as $index => $name) {
                if (in_array($name, $aliases, true)) {
                    $columns[$canonical] = $index;
                    break;
                }
            }
        }
        return $columns;
    }

    private static function parseDate(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \RuntimeException("Datum konnte nicht gelesen werden: {$value}");
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}
