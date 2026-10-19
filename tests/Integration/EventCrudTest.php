<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class EventCrudTest extends TestCase
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

    public function testCreateListUpdateAndDeleteEvent(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Zahnarzt',
            'starts_at' => '2026-03-10 14:00:00',
            'ends_at' => '2026-03-10 15:00:00',
            'scope' => 'family',
        ]);
        self::assertTrue($create['ok'], json_encode($create));
        $eventId = $create['data']['id'];

        $list = $client->request('GET', '/api/events?from=2026-03-01&to=2026-03-31');
        self::assertTrue($list['ok']);
        self::assertCount(1, $list['data']);
        self::assertSame('Zahnarzt', $list['data'][0]['title']);

        $update = $client->request('PUT', "/api/events/{$eventId}", ['title' => 'Zahnarzt (verschoben)']);
        self::assertTrue($update['ok']);

        $show = $client->request('GET', "/api/events/{$eventId}");
        self::assertTrue($show['ok']);
        self::assertSame('Zahnarzt (verschoben)', $show['data']['title']);

        $delete = $client->request('DELETE', "/api/events/{$eventId}");
        self::assertTrue($delete['ok']);

        $listAfterDelete = $client->request('GET', '/api/events?from=2026-03-01&to=2026-03-31');
        self::assertCount(0, $listAfterDelete['data']);
    }

    public function testFamilyScopeEventAutoAddsAllCurrentMembersAsParticipants(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Familienfeier',
            'starts_at' => '2026-04-01 10:00:00',
            'ends_at' => '2026-04-01 12:00:00',
            'scope' => 'family',
        ]);
        self::assertTrue($create['ok']);

        $show = $client->request('GET', "/api/events/{$create['data']['id']}");

        self::assertNotEmpty($show['data']['participant_ids'], 'Ein Familientermin muss automatisch alle aktuellen Mitglieder als Teilnehmer eintragen.');
    }

    public function testPersonalScopeEventWithoutParticipantsIsRejected(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Nur für jemanden',
            'starts_at' => '2026-04-01 10:00:00',
            'ends_at' => '2026-04-01 11:00:00',
            'scope' => 'personal',
        ]);

        self::assertFalse($create['ok']);
        self::assertSame(422, $create['status']);
    }

    public function testRecurringEventMaterializesExpectedNumberOfInstances(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Wöchentliches Training',
            'starts_at' => '2026-01-05 18:00:00',
            'ends_at' => '2026-01-05 19:00:00',
            'scope' => 'family',
            'recurrence_rule' => 'FREQ=WEEKLY;COUNT=4',
        ]);
        self::assertTrue($create['ok']);
        self::assertSame(4, $create['data']['occurrences']);

        $list = $client->request('GET', '/api/events?from=2026-01-01&to=2026-01-31');
        self::assertCount(4, $list['data']);
    }

    public function testEventCreationAddsReminderForCreatorByDefault(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Zahnarzt',
            'starts_at' => '2026-03-10 14:00:00', 'ends_at' => '2026-03-10 15:00:00',
            'scope' => 'family',
        ]);
        self::assertTrue($create['ok']);

        $reminders = $client->request('GET', "/api/events/{$create['data']['id']}/reminders");
        self::assertTrue($reminders['ok']);
        self::assertCount(1, $reminders['data'], 'Ohne explizites add_reminder=false soll standardmäßig eine Erinnerung angelegt werden.');
        self::assertSame(30, $reminders['data'][0]['remind_before_minutes']);
    }

    public function testEventCreationWithoutReminderCreatesNone(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Zahnarzt',
            'starts_at' => '2026-03-10 14:00:00', 'ends_at' => '2026-03-10 15:00:00',
            'scope' => 'family',
            'add_reminder' => false,
        ]);
        self::assertTrue($create['ok']);

        $reminders = $client->request('GET', "/api/events/{$create['data']['id']}/reminders");
        self::assertCount(0, $reminders['data'], 'add_reminder=false darf keine Erinnerung anlegen.');
    }

    public function testEventCreationHonorsCustomReminderMinutes(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Zahnarzt',
            'starts_at' => '2026-03-10 14:00:00', 'ends_at' => '2026-03-10 15:00:00',
            'scope' => 'family',
            'reminder_minutes' => 120,
        ]);
        self::assertTrue($create['ok']);

        $reminders = $client->request('GET', "/api/events/{$create['data']['id']}/reminders");
        self::assertSame(120, $reminders['data'][0]['remind_before_minutes']);
    }

    public function testRecurringEventAddsReminderToEveryOccurrence(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/events', [
            'title' => 'Training',
            'starts_at' => '2026-01-05 18:00:00', 'ends_at' => '2026-01-05 19:00:00',
            'scope' => 'family',
            'recurrence_rule' => 'FREQ=WEEKLY;COUNT=3',
        ]);
        self::assertTrue($create['ok']);

        $list = $client->request('GET', '/api/events?from=2026-01-01&to=2026-01-31');
        self::assertCount(3, $list['data']);

        foreach ($list['data'] as $occurrence) {
            $reminders = $client->request('GET', "/api/events/{$occurrence['id']}/reminders");
            self::assertCount(1, $reminders['data'], 'Jede einzelne Instanz der Serie soll ihre eigene Erinnerung bekommen.');
        }
    }

    public function testEventsAreScopedToOwnFamilyOnly(): void
    {
        $familyA = $this->loggedInClient();
        $familyA->request('POST', '/api/events', [
            'title' => 'Termin von Familie A',
            'starts_at' => '2026-05-01 10:00:00', 'ends_at' => '2026-05-01 11:00:00',
            'scope' => 'family',
        ]);

        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registerB = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Familie B', 'admin_name' => 'Bea',
            'email' => 'bea@example.test', 'password' => 'anderes-passwort',
        ]);
        $familyB = new \ApiClient(ApiServer::baseUrl(), $registerB['data']['token']);

        $listForB = $familyB->request('GET', '/api/events?from=2026-05-01&to=2026-05-31');

        self::assertCount(0, $listForB['data'], 'Familie B darf die Termine von Familie A nicht sehen (Mandantentrennung).');
    }
}
