<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testHEscapesHtmlSpecialCharacters(): void
    {
        self::assertSame('&lt;script&gt;&amp;&quot;&#039;', h('<script>&"\''));
    }

    public function testHHandlesNullAsEmptyString(): void
    {
        self::assertSame('', h(null));
    }

    public function testFormatGermanMonth(): void
    {
        self::assertSame('März 2026', formatGermanMonth(new \DateTimeImmutable('2026-03-15')));
        self::assertSame('Dezember 2025', formatGermanMonth(new \DateTimeImmutable('2025-12-01')));
    }

    public function testFormatGermanDateTime(): void
    {
        self::assertSame('03.11.2026 18:00', formatGermanDateTime('2026-11-03 18:00:00'));
    }

    public function testFormatGermanDateTimeReturnsRawInputOnInvalidFormat(): void
    {
        self::assertSame('not-a-date', formatGermanDateTime('not-a-date'));
    }

    public function testBuildCalendarUrlForMonthView(): void
    {
        self::assertSame('calendar.php?view=month&month=2026-03', buildCalendarUrl('month', '2026-03', '2026-03-15', []));
    }

    public function testBuildCalendarUrlForMonthViewWithHiddenMembers(): void
    {
        self::assertSame(
            'calendar.php?view=month&month=2026-03&hide=1%2C3',
            buildCalendarUrl('month', '2026-03', '2026-03-15', [1, 3])
        );
    }

    public function testBuildCalendarUrlForWeekAndDayViewsUsesDateInsteadOfMonth(): void
    {
        self::assertSame('calendar.php?view=week&date=2026-03-10', buildCalendarUrl('week', '2026-03', '2026-03-10', []));
        self::assertSame('calendar.php?view=day&date=2026-03-10', buildCalendarUrl('day', '2026-03', '2026-03-10', []));
    }

    public function testBuildCalendarUrlFallsBackToMonthViewForUnknownView(): void
    {
        self::assertSame('calendar.php?view=month&month=2026-03', buildCalendarUrl('year', '2026-03', '2026-03-10', []));
    }

    public function testSanitizeCalendarBackKeepsAValidMonthView(): void
    {
        self::assertSame('view=month&month=2026-03', sanitizeCalendarBack('view=month&month=2026-03'));
    }

    public function testSanitizeCalendarBackKeepsAValidWeekViewWithHiddenMembers(): void
    {
        self::assertSame(
            'view=week&date=2026-03-10&hide=1%2C3',
            sanitizeCalendarBack('view=week&date=2026-03-10&hide=1,3')
        );
    }

    public function testSanitizeCalendarBackFallsBackToCurrentMonthOnEmptyOrGarbageInput(): void
    {
        $expected = 'view=month&month=' . date('Y-m');
        self::assertSame($expected, sanitizeCalendarBack(''));
        self::assertSame($expected, sanitizeCalendarBack('; rm -rf /'));
    }

    public function testSanitizeCalendarBackKeepsTheViewButFallsBackToTodayOnAnInvalidDate(): void
    {
        self::assertSame('view=week&date=' . date('Y-m-d'), sanitizeCalendarBack('view=week&date=not-a-date'));
    }

    public function testSanitizeCalendarBackIgnoresMalformedHideList(): void
    {
        // "hide" darf nur eine Kommaliste von Zahlen sein - alles andere
        // (z.B. ein Einschleusungsversuch über weitere &-Parameter) wird
        // stillschweigend verworfen statt übernommen.
        self::assertSame(
            'view=month&month=2026-03',
            sanitizeCalendarBack('view=month&month=2026-03&hide=1;DROP')
        );
    }

    public function testFormatGermanFullDate(): void
    {
        self::assertSame('Dienstag, 10. März 2026', formatGermanFullDate(new \DateTimeImmutable('2026-03-10')));
    }

    public function testEventColorIsGreyForFamilyEvents(): void
    {
        $event = ['scope' => 'family', 'participant_ids' => []];
        self::assertSame('#64748b', eventColor($event, []));
    }

    public function testEventColorUsesFirstParticipantsDisplayColor(): void
    {
        $event = ['scope' => 'personal', 'participant_ids' => [7, 9]];
        $membersById = [7 => ['display_color' => '#ff0000'], 9 => ['display_color' => '#00ff00']];

        self::assertSame('#ff0000', eventColor($event, $membersById));
    }
}
