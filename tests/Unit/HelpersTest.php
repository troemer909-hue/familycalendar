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

    public function testBuildCalendarUrlWithoutHiddenMembers(): void
    {
        self::assertSame('calendar.php?month=2026-03', buildCalendarUrl('2026-03', []));
    }

    public function testBuildCalendarUrlWithHiddenMembers(): void
    {
        self::assertSame('calendar.php?month=2026-03&hide=1%2C3', buildCalendarUrl('2026-03', [1, 3]));
    }
}
