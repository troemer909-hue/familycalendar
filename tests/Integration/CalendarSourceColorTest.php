<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Core\MigrationRunner;
use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Deckt den Nutzer-Wunsch "ich würde für termine von abonnierten kalendern
 * gern extra farben verteilen, egal wem sie zugeordnet sind" ab - jede
 * Kalenderquelle bekommt automatisch eine feste Farbe aus einer Palette
 * (CalendarSourceController::nextPaletteColor()), die beim Rendern Vorrang
 * vor der Mitglieder-Farbe hat (eventColor() in Helpers.php).
 */
final class CalendarSourceColorTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();
    }

    private function loggedInClient(): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'sicheres-passwort',
        ]);

        return new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
    }

    private function sampleIcs(string $uid, string $summary): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\n"
            . "SUMMARY:{$summary}\r\n"
            . "DTSTART:20260310T143000\r\n"
            . "DTEND:20260310T150000\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    public function testNewCalendarSourceGetsAnAutomaticColor(): void
    {
        $client = $this->loggedInClient();

        $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs('event-1', 'Schulfest'),
        ]);

        $sources = $client->request('GET', '/api/calendar-sources');
        self::assertTrue($sources['ok']);
        self::assertNotEmpty($sources['data'][0]['color']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $sources['data'][0]['color']);
    }

    public function testTwoSourcesGetDifferentColors(): void
    {
        $client = $this->loggedInClient();

        $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs('event-1', 'Schulfest'),
        ]);
        $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Vereinskalender',
            'ics_content' => $this->sampleIcs('event-2', 'Training'),
        ]);

        $sources = $client->request('GET', '/api/calendar-sources');
        self::assertCount(2, $sources['data']);
        self::assertNotSame($sources['data'][0]['color'], $sources['data'][1]['color']);
    }

    public function testImportedEventColorOverridesParticipantColor(): void
    {
        $client = $this->loggedInClient();

        $members = $client->request('GET', '/api/members');
        $memberId = $members['data'][0]['id'];

        $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs('event-1', 'Schulfest'),
            'family_member_id' => $memberId,
        ]);

        $sources = $client->request('GET', '/api/calendar-sources');
        $sourceColor = $sources['data'][0]['color'];

        $events = $client->request('GET', '/api/events?from=2026-03-01&to=2026-03-31');
        self::assertCount(1, $events['data']);
        self::assertSame($sourceColor, $events['data'][0]['source_color']);
        self::assertNotSame($sourceColor, $members['data'][0]['display_color'], 'Testdaten-Annahme: Quellen-Farbe unterscheidet sich von der Standard-Mitgliederfarbe.');
    }

    /**
     * Deckt Migration 018_calendar_source_color_backfill.sql ab: Quellen,
     * die vor Einführung der Farbvergabe angelegt wurden (color IS NULL),
     * bekommen beim Nachtrag eine Farbe zugewiesen - verschiedene Quellen
     * derselben Familie sollen dabei unterschiedliche Farben bekommen.
     */
    public function testBackfillMigrationAssignsColorsToPreExistingSources(): void
    {
        $this->db->exec("INSERT INTO families (name) VALUES ('Altbestand-Familie')");
        $familyId = (int) $this->db->lastInsertId();

        $insert = $this->db->prepare(
            "INSERT INTO calendar_sources (family_id, name, type, status, created_at)
             VALUES (:family_id, :name, 'import_ics', 'active', :created_at)"
        );
        foreach (['Quelle A', 'Quelle B', 'Quelle C'] as $i => $name) {
            $insert->execute([
                'family_id' => $familyId,
                'name' => $name,
                'created_at' => (new \DateTimeImmutable("+{$i} minutes"))->format('Y-m-d H:i:s'),
            ]);
        }

        $this->db->exec("DELETE FROM schema_migrations WHERE migration = '018_calendar_source_color_backfill.sql'");

        $runner = new MigrationRunner($this->db, dirname(__DIR__, 2) . '/database/migrations');
        self::assertContains('018_calendar_source_color_backfill.sql', $runner->applyPending());

        $colors = $this->db->query("SELECT color FROM calendar_sources WHERE family_id = {$familyId} ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(3, $colors);
        foreach ($colors as $color) {
            self::assertNotEmpty($color, 'Jede vorher farblose Quelle muss nach dem Backfill eine Farbe haben.');
        }
        self::assertSame($colors, array_unique($colors), 'Quellen derselben Familie sollen unterschiedliche Farben bekommen.');
    }
}
