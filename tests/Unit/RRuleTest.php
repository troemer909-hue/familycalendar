<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\RRule;
use PHPUnit\Framework\TestCase;

final class RRuleTest extends TestCase
{
    public function testDailyWithCount(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 10:00:00');
        $end = new \DateTimeImmutable('2026-01-01 11:00:00');

        $occurrences = RRule::expand('FREQ=DAILY;COUNT=3', $start, $end);

        self::assertCount(3, $occurrences);
        self::assertSame('2026-01-01', $occurrences[0]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-01-02', $occurrences[1]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-01-03', $occurrences[2]['starts_at']->format('Y-m-d'));
    }

    public function testDurationIsPreservedAcrossOccurrences(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 10:00:00');
        $end = new \DateTimeImmutable('2026-01-01 11:30:00'); // 1,5h Dauer

        $occurrences = RRule::expand('FREQ=WEEKLY;COUNT=2', $start, $end);

        foreach ($occurrences as $occ) {
            $diff = $occ['starts_at']->diff($occ['ends_at']);
            self::assertSame('01:30', $diff->format('%H:%I'));
        }
    }

    public function testWeeklyWithInterval(): void
    {
        $start = new \DateTimeImmutable('2026-01-05 09:00:00'); // Montag
        $end = new \DateTimeImmutable('2026-01-05 10:00:00');

        $occurrences = RRule::expand('FREQ=WEEKLY;INTERVAL=2;COUNT=3', $start, $end);

        self::assertSame('2026-01-05', $occurrences[0]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-01-19', $occurrences[1]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-02-02', $occurrences[2]['starts_at']->format('Y-m-d'));
    }

    public function testUntilStopsBeforeCountWouldContinue(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 10:00:00');
        $end = new \DateTimeImmutable('2026-01-01 11:00:00');

        $occurrences = RRule::expand('FREQ=DAILY;UNTIL=20260103', $start, $end);

        self::assertCount(3, $occurrences); // 1., 2., 3. Januar - 4. liegt nach UNTIL
    }

    /**
     * Regressionstest für den ursprünglichen Monats-Clamping-Bug: wird jede
     * Instanz aus der VORHERIGEN (bereits geklammerten) berechnet statt
     * immer aus dem Original-Start, "klebt" der Tag nach dem ersten
     * Clamping fest (31.1. -> 28.2. -> 28.3. statt 31.3.). RRule::expand()
     * berechnet stattdessen jede Instanz direkt aus $firstStart.
     */
    public function testMonthlyClampingDoesNotStickAfterShortMonth(): void
    {
        $start = new \DateTimeImmutable('2026-01-31 12:00:00');
        $end = new \DateTimeImmutable('2026-01-31 13:00:00');

        $occurrences = RRule::expand('FREQ=MONTHLY;COUNT=5', $start, $end);

        self::assertSame('2026-01-31', $occurrences[0]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-02-28', $occurrences[1]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-03-31', $occurrences[2]['starts_at']->format('Y-m-d'), 'Darf nach dem Clamping im Februar nicht bei Tag 28 "kleben bleiben".');
        self::assertSame('2026-04-30', $occurrences[3]['starts_at']->format('Y-m-d'));
        self::assertSame('2026-05-31', $occurrences[4]['starts_at']->format('Y-m-d'));
    }

    public function testYearlyClampingHandlesLeapDay(): void
    {
        $start = new \DateTimeImmutable('2024-02-29 10:00:00'); // Schaltjahr
        $end = new \DateTimeImmutable('2024-02-29 11:00:00');

        $occurrences = RRule::expand('FREQ=YEARLY;COUNT=2', $start, $end);

        self::assertSame('2024-02-29', $occurrences[0]['starts_at']->format('Y-m-d'));
        self::assertSame('2025-02-28', $occurrences[1]['starts_at']->format('Y-m-d'));
    }

    public function testRejectsUnsupportedFrequency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RRule::expand('FREQ=HOURLY;COUNT=3', new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));
    }

    public function testRejectsMissingCountAndUntil(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RRule::expand('FREQ=DAILY', new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));
    }

    public function testRejectsUntilBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RRule::expand('FREQ=DAILY;UNTIL=20200101', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01 01:00:00'));
    }

    public function testNeverExceedsMaxOccurrencesCap(): void
    {
        $occurrences = RRule::expand('FREQ=DAILY;COUNT=10000', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01 01:00:00'));

        self::assertLessThanOrEqual(366, count($occurrences));
    }
}
