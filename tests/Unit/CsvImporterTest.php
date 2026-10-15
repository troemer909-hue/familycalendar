<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\CsvImporter;
use PHPUnit\Framework\TestCase;

final class CsvImporterTest extends TestCase
{
    public function testParsesEnglishHeadersWithCommaDelimiter(): void
    {
        $csv = "title,starts_at,ends_at\nZahnarzt,2026-03-10 14:00,2026-03-10 15:00\n";

        $events = CsvImporter::parse($csv);

        self::assertCount(1, $events);
        self::assertSame('Zahnarzt', $events[0]['title']);
        self::assertSame('2026-03-10 14:00:00', $events[0]['starts_at']);
    }

    public function testParsesGermanHeadersWithSemicolonDelimiter(): void
    {
        $csv = "Titel;Beginn;Ende;Ort\nTraining;2026-03-10 18:00;2026-03-10 19:30;Sporthalle\n";

        $events = CsvImporter::parse($csv);

        self::assertCount(1, $events);
        self::assertSame('Training', $events[0]['title']);
        self::assertSame('Sporthalle', $events[0]['location']);
    }

    public function testSkipsIncompleteRowsInsteadOfAborting(): void
    {
        $csv = "title,starts_at,ends_at\n"
            . "Vollständig,2026-01-01 10:00,2026-01-01 11:00\n"
            . ",2026-01-02 10:00,2026-01-02 11:00\n" // Titel fehlt
            . "Auch vollständig,2026-01-03 10:00,2026-01-03 11:00\n";

        $events = CsvImporter::parse($csv);

        self::assertCount(2, $events);
        self::assertSame('Vollständig', $events[0]['title']);
        self::assertSame('Auch vollständig', $events[1]['title']);
    }

    public function testAllDayRecognizesGermanAndEnglishTruthyValues(): void
    {
        $csv = "title,starts_at,ends_at,ganztägig\nUrlaub,2026-07-01,2026-07-03,ja\n";

        $events = CsvImporter::parse($csv);

        self::assertTrue($events[0]['all_day']);
    }

    public function testThrowsWhenRequiredColumnsAreMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        CsvImporter::parse("title,ort\nTermin,Zuhause\n");
    }

    public function testThrowsWhenOnlyHeaderRowPresent(): void
    {
        $this->expectException(\RuntimeException::class);
        CsvImporter::parse("title,starts_at,ends_at\n");
    }

    public function testThrowsWhenAllDataRowsAreIncomplete(): void
    {
        $this->expectException(\RuntimeException::class);
        CsvImporter::parse("title,starts_at,ends_at\n,2026-01-01,2026-01-02\n");
    }

    public function testUidIsStableAcrossIdenticalReparse(): void
    {
        $csv = "title,starts_at,ends_at\nFixtermin,2026-01-01 10:00,2026-01-01 11:00\n";

        $first = CsvImporter::parse($csv);
        $second = CsvImporter::parse($csv);

        self::assertSame($first[0]['uid'], $second[0]['uid']);
    }
}
