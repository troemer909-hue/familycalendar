<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\IcsParser;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    public function testParsesBasicTimedEvent(): void
    {
        $ics = "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:abc-123\r\n"
            . "SUMMARY:Zahnarzt\r\n"
            . "LOCATION:Hauptstr. 1\r\n"
            . "DTSTART:20260310T143000\r\n"
            . "DTEND:20260310T150000\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $events = IcsParser::parse($ics);

        self::assertCount(1, $events);
        self::assertSame('abc-123', $events[0]['uid']);
        self::assertSame('Zahnarzt', $events[0]['title']);
        self::assertSame('Hauptstr. 1', $events[0]['location']);
        self::assertSame('2026-03-10 14:30:00', $events[0]['starts_at']);
        self::assertSame('2026-03-10 15:00:00', $events[0]['ends_at']);
        self::assertFalse($events[0]['all_day']);
    }

    public function testAllDayEventConvertsExclusiveDtendToInclusive(): void
    {
        // ICS: DTEND ist bei VALUE=DATE exklusiv (erster Tag NACH dem
        // Termin) - ein 3-tägiger Urlaub 1.-3. Juli hat DTEND:20260704.
        $ics = "BEGIN:VEVENT\r\n"
            . "SUMMARY:Urlaub\r\n"
            . "DTSTART;VALUE=DATE:20260701\r\n"
            . "DTEND;VALUE=DATE:20260704\r\n"
            . "END:VEVENT\r\n";

        $events = IcsParser::parse($ics);

        self::assertTrue($events[0]['all_day']);
        self::assertSame('2026-07-01 00:00:00', $events[0]['starts_at']);
        self::assertSame('2026-07-03 23:59:59', $events[0]['ends_at']);
    }

    public function testDurationIsUsedWhenNoDtend(): void
    {
        $ics = "BEGIN:VEVENT\r\n"
            . "SUMMARY:Training\r\n"
            . "DTSTART:20260101T100000\r\n"
            . "DURATION:PT1H30M\r\n"
            . "END:VEVENT\r\n";

        $events = IcsParser::parse($ics);

        self::assertSame('2026-01-01 11:30:00', $events[0]['ends_at']);
    }

    public function testMissingUidFallsBackToDeterministicHash(): void
    {
        $ics = "BEGIN:VEVENT\r\n"
            . "SUMMARY:Ohne UID\r\n"
            . "DTSTART:20260101T100000\r\n"
            . "DTEND:20260101T110000\r\n"
            . "END:VEVENT\r\n";

        $eventsA = IcsParser::parse($ics);
        $eventsB = IcsParser::parse($ics);

        self::assertNotSame('', $eventsA[0]['uid']);
        self::assertSame($eventsA[0]['uid'], $eventsB[0]['uid'], 'Derselbe Inhalt muss dieselbe UID ergeben (Upsert-Fähigkeit beim erneuten Import).');
    }

    public function testEscapedTextIsUnescaped(): void
    {
        $ics = "BEGIN:VEVENT\r\n"
            . "SUMMARY:Zahnarzt\\, Dr. Müller\\; Kontrolle\r\n"
            . "DESCRIPTION:Zeile 1\\nZeile 2\r\n"
            . "DTSTART:20260101T100000\r\n"
            . "DTEND:20260101T110000\r\n"
            . "END:VEVENT\r\n";

        $events = IcsParser::parse($ics);

        self::assertSame('Zahnarzt, Dr. Müller; Kontrolle', $events[0]['title']);
        self::assertSame("Zeile 1\nZeile 2", $events[0]['description']);
    }

    public function testFoldedLinesAreUnfoldedBeforeParsing(): void
    {
        // RFC5545 Line-Folding: die Folgezeile beginnt mit einem
        // Leerzeichen, das selbst NICHT Teil des Inhalts ist (reiner
        // Trenner) und beim Unfold entfernt wird - der Bruch liegt hier
        // bewusst MITTEN im Wort "umgebrochen", nicht an einer ohnehin
        // schon vorhandenen Leerstelle, um das nicht zu verwechseln.
        $ics = "BEGIN:VEVENT\r\n"
            . "SUMMARY:Ein sehr langer Titel der über mehrere Zeilen umgebro\r\n"
            . " chen wurde\r\n"
            . "DTSTART:20260101T100000\r\n"
            . "DTEND:20260101T110000\r\n"
            . "END:VEVENT\r\n";

        $events = IcsParser::parse($ics);

        self::assertSame('Ein sehr langer Titel der über mehrere Zeilen umgebrochen wurde', $events[0]['title']);
    }

    public function testThrowsWhenNoEventsFound(): void
    {
        $this->expectException(\RuntimeException::class);
        IcsParser::parse("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
    }

    public function testEventWithoutDtstartIsSkipped(): void
    {
        $ics = "BEGIN:VEVENT\r\n"
            . "SUMMARY:Ohne Start\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\n"
            . "SUMMARY:Mit Start\r\n"
            . "DTSTART:20260101T100000\r\n"
            . "END:VEVENT\r\n";

        $events = IcsParser::parse($ics);

        self::assertCount(1, $events);
        self::assertSame('Mit Start', $events[0]['title']);
    }
}
