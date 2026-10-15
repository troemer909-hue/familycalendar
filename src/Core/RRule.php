<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Minimaler, selbst geschriebener RRULE-Expander (RFC 5545, nur das
 * Teilset, das event_form.php erzeugt) - bewusst ohne Composer-
 * Abhängigkeit, passend zur restlichen Codebasis (vgl. composer.json
 * "suggest": rlanvin/php-rrule als Alternative für komplexere Regeln wie
 * BYDAY/BYMONTHDAY, die hier NICHT unterstützt werden).
 *
 * Unterstützt: FREQ=DAILY|WEEKLY|MONTHLY|YEARLY, INTERVAL, COUNT, UNTIL.
 * Es wird IMMER COUNT oder UNTIL verlangt - endlose Serien gibt es bewusst
 * nicht (sonst müsste "materialize ins events table" ewig weiterlaufen).
 */
final class RRule
{
    private const MAX_OCCURRENCES = 366;

    /**
     * @return array<int, array{starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable}>
     *         Liste INKLUSIVE des ersten Vorkommens (= $firstStart/$firstEnd selbst).
     */
    public static function expand(
        string $rrule,
        \DateTimeImmutable $firstStart,
        \DateTimeImmutable $firstEnd,
        int $maxOccurrences = self::MAX_OCCURRENCES
    ): array {
        $parts = self::parseParts($rrule);

        $freq = $parts['FREQ'] ?? null;
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            throw new \InvalidArgumentException('Nicht unterstützte FREQ (nur DAILY/WEEKLY/MONTHLY/YEARLY).');
        }

        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $count = isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null;
        $until = isset($parts['UNTIL']) ? self::parseUntil($parts['UNTIL']) : null;

        if ($count === null && $until === null) {
            throw new \InvalidArgumentException('RRULE benötigt COUNT oder UNTIL (keine endlosen Serien).');
        }

        $cap = min($maxOccurrences, self::MAX_OCCURRENCES);
        $duration = $firstStart->diff($firstEnd);

        $occurrences = [];
        $n = 0;

        while (($count === null || $n < $count) && count($occurrences) < $cap) {
            // WICHTIG: jede Instanz wird direkt aus $firstStart berechnet
            // (nicht verkettet aus der vorherigen) - sonst würde z.B. der
            // 31. Januar nach dem Clamping auf den 28. Februar für alle
            // folgenden Monate bei Tag 28 "kleben bleiben", obwohl März
            // wieder 31 Tage hat.
            $current = self::occurrenceAt($firstStart, $freq, $interval, $n);

            if ($until !== null && $current > $until) {
                break;
            }

            $occurrences[] = ['starts_at' => $current, 'ends_at' => $current->add($duration)];
            $n++;
        }

        if (empty($occurrences)) {
            throw new \InvalidArgumentException('Wiederholungsregel ergibt kein einziges Vorkommen (UNTIL vor dem Start?).');
        }

        return $occurrences;
    }

    private static function occurrenceAt(\DateTimeImmutable $first, string $freq, int $interval, int $n): \DateTimeImmutable
    {
        return match ($freq) {
            'DAILY' => $first->modify('+' . ($interval * $n) . ' day'),
            'WEEKLY' => $first->modify('+' . ($interval * $n) . ' week'),
            'MONTHLY' => self::addMonthsClamped($first, $interval * $n),
            'YEARLY' => self::addMonthsClamped($first, $interval * $n * 12),
        };
    }

    /**
     * Addiert Monate unter Beibehaltung des Tages, aber geklammert auf den
     * letzten Tag des Zielmonats (z.B. 31. Januar + 1 Monat -> 28./29.
     * Februar statt eines PHP-typischen Überlaufs in den März).
     */
    private static function addMonthsClamped(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n') + $months;
        $day = (int) $date->format('j');

        $year += intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;

        $lastDayOfTargetMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('j');

        return $date->setDate($year, $month, min($day, $lastDayOfTargetMonth));
    }

    /** @return array<string,string> */
    private static function parseParts(string $rrule): array
    {
        $rrule = preg_replace('/^RRULE:/i', '', trim($rrule));

        $parts = [];
        foreach (explode(';', $rrule) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[strtoupper(trim($key))] = trim($value);
        }
        return $parts;
    }

    private static function parseUntil(string $value): \DateTimeImmutable
    {
        $value = rtrim($value, 'Z');

        if (strlen($value) === 8) {
            $date = \DateTimeImmutable::createFromFormat('Ymd', $value);
        } else {
            $date = \DateTimeImmutable::createFromFormat('Ymd\THis', $value);
        }

        if (!$date) {
            throw new \InvalidArgumentException("Ungültiges UNTIL-Datum in RRULE: {$value}");
        }

        return $date->setTime(23, 59, 59);
    }
}
