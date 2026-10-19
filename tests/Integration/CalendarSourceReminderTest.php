<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

/**
 * Deckt den Nutzer-Report "beim einrichten eines import oder abruf eines
 * kalenders kann ich keine benachrichtigungen aktivieren" ab - importierte/
 * abonnierte Termine liefen bisher komplett an EventController (und damit
 * an dessen Default-Erinnerungs-Logik) vorbei, siehe
 * CalendarSync::importEvents()/CalendarSourceController.
 */
final class CalendarSourceReminderTest extends TestCase
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

    public function testImportIcsWithReminderMinutesCreatesReminderForLoginParticipant(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
            'reminder_minutes' => 60,
        ]);
        self::assertTrue($import['ok'], json_encode($import));
        self::assertSame(1, $import['data']['imported']);

        $events = $client->request('GET', '/api/events?from=2026-03-01&to=2026-03-31');
        self::assertCount(1, $events['data']);
        $eventId = $events['data'][0]['id'];

        $reminders = $client->request('GET', "/api/events/{$eventId}/reminders");
        self::assertTrue($reminders['ok']);
        self::assertCount(1, $reminders['data'], 'Ohne scope-spezifische Zuweisung ist der Admin automatisch Teilnehmer und muss eine Erinnerung bekommen.');
        self::assertSame(60, $reminders['data'][0]['remind_before_minutes']);
    }

    public function testImportIcsWithoutReminderMinutesCreatesNoReminder(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
        ]);
        self::assertTrue($import['ok'], json_encode($import));

        $events = $client->request('GET', '/api/events?from=2026-03-01&to=2026-03-31');
        $eventId = $events['data'][0]['id'];

        $reminders = $client->request('GET', "/api/events/{$eventId}/reminders");
        self::assertCount(0, $reminders['data'], 'Ohne reminder_minutes darf kein Import automatisch Erinnerungen anlegen.');
    }

    public function testImportIcsRejectsOutOfRangeReminderMinutes(): void
    {
        $client = $this->loggedInClient();

        $import = $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
            'reminder_minutes' => 99999,
        ]);

        self::assertFalse($import['ok']);
        self::assertSame(422, $import['status']);
    }

    public function testCalendarSourceStoresDefaultReminderMinutes(): void
    {
        $client = $this->loggedInClient();

        $client->request('POST', '/api/calendar-sources/import-ics', [
            'name' => 'Schulkalender',
            'ics_content' => $this->sampleIcs(),
            'reminder_minutes' => 1440,
        ]);

        $sources = $client->request('GET', '/api/calendar-sources');
        self::assertTrue($sources['ok']);
        self::assertSame(1440, $sources['data'][0]['default_reminder_minutes']);
    }

    public function testImportCsvWithReminderMinutesCreatesReminder(): void
    {
        $client = $this->loggedInClient();

        $csv = "Titel,Beginn,Ende\nSportfest,2026-04-01 09:00,2026-04-01 12:00\n";

        $import = $client->request('POST', '/api/calendar-sources/import-csv', [
            'name' => 'Vereinstermine',
            'csv_content' => $csv,
            'reminder_minutes' => 30,
        ]);
        self::assertTrue($import['ok'], json_encode($import));

        $events = $client->request('GET', '/api/events?from=2026-04-01&to=2026-04-30');
        self::assertCount(1, $events['data']);

        $reminders = $client->request('GET', "/api/events/{$events['data'][0]['id']}/reminders");
        self::assertCount(1, $reminders['data']);
        self::assertSame(30, $reminders['data'][0]['remind_before_minutes']);
    }
}
