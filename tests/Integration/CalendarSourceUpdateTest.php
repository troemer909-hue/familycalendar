<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die Nutzer-Frage "kann man Benachrichtigungen und Farben auch
 * nachträglich ändern?" ab - reminder_minutes und color konnten bisher
 * nur beim Anlegen einer Quelle gesetzt werden (siehe
 * CalendarSourceController::createSourceRow()), PUT /api/calendar-sources/
 * {id} erlaubt jetzt beides nachträglich zu ändern.
 */
final class CalendarSourceUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
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

    private function sampleIcs(): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:event-1\r\n"
            . "SUMMARY:Schulfest\r\n"
            . "DTSTART:20260310T143000\r\n"
            . "DTEND:20260310T150000\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    public function testReminderMinutesCanBeChangedAfterCreation(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
        ]);
        $sourceId = $import['data']['id'];

        $update = $client->request('PUT', "/api/calendar-sources/{$sourceId}", [
            'reminder_minutes' => 120,
        ]);
        self::assertTrue($update['ok'], json_encode($update));

        $sources = $client->request('GET', '/api/calendar-sources');
        self::assertSame(120, $sources['data'][0]['default_reminder_minutes']);
    }

    public function testReminderMinutesCanBeClearedAfterCreation(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
            'reminder_minutes' => 60,
        ]);
        $sourceId = $import['data']['id'];

        $update = $client->request('PUT', "/api/calendar-sources/{$sourceId}", [
            'reminder_minutes' => '',
        ]);
        self::assertTrue($update['ok']);

        $sources = $client->request('GET', '/api/calendar-sources');
        self::assertNull($sources['data'][0]['default_reminder_minutes']);
    }

    public function testColorCanBeChangedAfterCreation(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
        ]);
        $sourceId = $import['data']['id'];
        $originalColor = $client->request('GET', '/api/calendar-sources')['data'][0]['color'];

        $newColor = $originalColor === '#f97316' ? '#0ea5e9' : '#f97316';
        $update = $client->request('PUT', "/api/calendar-sources/{$sourceId}", [
            'color' => $newColor,
        ]);
        self::assertTrue($update['ok'], json_encode($update));

        $sources = $client->request('GET', '/api/calendar-sources');
        self::assertSame($newColor, $sources['data'][0]['color']);

        $events = $client->request('GET', '/api/events?from=2026-03-01&to=2026-03-31');
        self::assertSame($newColor, $events['data'][0]['source_color'], 'Geänderte Farbe muss sofort auch bei bereits importierten Terminen greifen.');
    }

    public function testColorMustBeFromThePalette(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
        ]);
        $sourceId = $import['data']['id'];

        $update = $client->request('PUT', "/api/calendar-sources/{$sourceId}", [
            'color' => '#123456',
        ]);
        self::assertFalse($update['ok']);
        self::assertSame(422, $update['status']);
    }

    public function testUpdateOnForeignSourceIsRejected(): void
    {
        $clientA = $this->loggedInClient();
        $import = $clientA->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
        ]);
        $sourceId = $import['data']['id'];

        $anonymousB = new \ApiClient(ApiServer::baseUrl(), null);
        $registerB = $anonymousB->request('POST', '/api/auth/register', [
            'family_name' => 'Andere Familie',
            'admin_name' => 'Admin B',
            'email' => 'adminb@example.test',
            'password' => 'sicheres-passwort',
        ]);
        $clientB = new \ApiClient(ApiServer::baseUrl(), $registerB['data']['token']);

        $update = $clientB->request('PUT', "/api/calendar-sources/{$sourceId}", [
            'reminder_minutes' => 60,
        ]);
        self::assertFalse($update['ok']);
        self::assertSame(404, $update['status']);
    }
}
