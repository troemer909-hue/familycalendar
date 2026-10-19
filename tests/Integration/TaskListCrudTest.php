<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class TaskListCrudTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    private function loggedInClient(string $email = 'admin@example.test'): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Admin',
            'email' => $email,
            'password' => 'sicheres-passwort',
        ]);

        return new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
    }

    public function testCreateListAddTaskToggleAndDelete(): void
    {
        $client = $this->loggedInClient();

        $createList = $client->request('POST', '/api/task-lists', ['name' => 'Einkaufsliste']);
        self::assertTrue($createList['ok'], json_encode($createList));
        $listId = $createList['data']['id'];

        $index = $client->request('GET', '/api/task-lists');
        self::assertTrue($index['ok']);
        self::assertCount(1, $index['data']);
        self::assertSame('Einkaufsliste', $index['data'][0]['name']);
        self::assertSame(0, $index['data'][0]['total_count']);

        $createTask = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Milch kaufen']);
        self::assertTrue($createTask['ok'], json_encode($createTask));
        $taskId = $createTask['data']['id'];

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertTrue($tasks['ok']);
        self::assertCount(1, $tasks['data']);
        self::assertFalse($tasks['data'][0]['is_done']);

        $toggle = $client->request('PUT', "/api/tasks/{$taskId}", ['is_done' => true]);
        self::assertTrue($toggle['ok']);
        self::assertTrue($toggle['data']['is_done']);

        $tasksAfterToggle = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertTrue($tasksAfterToggle['data'][0]['is_done']);

        $indexAfterToggle = $client->request('GET', '/api/task-lists');
        self::assertSame(1, $indexAfterToggle['data'][0]['total_count']);
        self::assertSame(0, $indexAfterToggle['data'][0]['open_count']);

        $deleteTask = $client->request('DELETE', "/api/tasks/{$taskId}");
        self::assertTrue($deleteTask['ok']);

        $tasksAfterDelete = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertCount(0, $tasksAfterDelete['data']);
    }

    public function testTaskCanBeAssignedToFamilyMember(): void
    {
        $client = $this->loggedInClient();

        $member = $client->request('POST', '/api/members', ['display_name' => 'Kind', 'display_color' => '#ff0000']);
        self::assertTrue($member['ok'], json_encode($member));
        $memberId = $member['data']['id'];

        $createList = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $createList['data']['id'];

        $createTask = $client->request('POST', "/api/task-lists/{$listId}/tasks", [
            'title' => 'Zimmer aufräumen',
            'assigned_member_id' => $memberId,
            'due_date' => '2026-06-01',
        ]);
        self::assertTrue($createTask['ok'], json_encode($createTask));

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertSame($memberId, $tasks['data'][0]['assigned_member_id']);
        self::assertSame('Kind', $tasks['data'][0]['assigned_member_name']);
        self::assertSame('2026-06-01', $tasks['data'][0]['due_date']);
    }

    public function testRecurringTaskCreatesIndependentOccurrences(): void
    {
        $client = $this->loggedInClient();

        $createList = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $createList['data']['id'];

        $createTask = $client->request('POST', "/api/task-lists/{$listId}/tasks", [
            'title' => 'Müll rausbringen',
            'due_date' => '2026-01-05',
            'recurrence_rule' => 'FREQ=WEEKLY;COUNT=4',
        ]);
        self::assertTrue($createTask['ok'], json_encode($createTask));
        self::assertSame(4, $createTask['data']['occurrences']);

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertCount(4, $tasks['data']);
        $dueDates = array_map(static fn (array $t): string => $t['due_date'], $tasks['data']);
        sort($dueDates);
        self::assertSame(['2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26'], $dueDates);

        // Jede Instanz ist eigenständig - eine einzelne abzuhaken darf die
        // anderen nicht berühren (kein Serien-Tracking wie bei Terminen).
        $toggle = $client->request('PUT', "/api/tasks/{$tasks['data'][0]['id']}", ['is_done' => true]);
        self::assertTrue($toggle['ok']);

        $tasksAfterToggle = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        $doneCount = count(array_filter($tasksAfterToggle['data'], static fn (array $t): bool => $t['is_done']));
        self::assertSame(1, $doneCount, 'Nur die abgehakte Instanz darf erledigt sein, nicht die ganze Serie.');
    }

    public function testRecurringTaskWithoutDueDateIsRejected(): void
    {
        $client = $this->loggedInClient();

        $createList = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $createList['data']['id'];

        $createTask = $client->request('POST', "/api/task-lists/{$listId}/tasks", [
            'title' => 'Müll rausbringen',
            'recurrence_rule' => 'FREQ=WEEKLY;COUNT=4',
        ]);

        self::assertFalse($createTask['ok']);
        self::assertSame(422, $createTask['status']);
    }

    public function testAssigningToAForeignFamilyMemberIsRejected(): void
    {
        $familyA = $this->loggedInClient('a@example.test');
        $familyB = $this->loggedInClient('b@example.test');

        $foreignMember = $familyB->request('POST', '/api/members', ['display_name' => 'Fremd', 'display_color' => '#00ff00']);
        $foreignMemberId = $foreignMember['data']['id'];

        $createList = $familyA->request('POST', '/api/task-lists', ['name' => 'Liste A']);
        $listId = $createList['data']['id'];

        $createTask = $familyA->request('POST', "/api/task-lists/{$listId}/tasks", [
            'title' => 'Aufgabe',
            'assigned_member_id' => $foreignMemberId,
        ]);

        self::assertFalse($createTask['ok']);
        self::assertSame(422, $createTask['status']);
    }

    public function testListsAndTasksAreScopedToOwnFamilyOnly(): void
    {
        $familyA = $this->loggedInClient('a@example.test');
        $createList = $familyA->request('POST', '/api/task-lists', ['name' => 'Liste von Familie A']);
        $listId = $createList['data']['id'];
        $familyA->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Aufgabe von Familie A']);

        $familyB = $this->loggedInClient('b@example.test');

        $indexForB = $familyB->request('GET', '/api/task-lists');
        self::assertCount(0, $indexForB['data'], 'Familie B darf die Listen von Familie A nicht sehen (Mandantentrennung).');

        $tasksForB = $familyB->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertFalse($tasksForB['ok']);
        self::assertSame(404, $tasksForB['status'], 'Familie B darf fremde Listen-IDs nicht einmal für die Existenzprüfung nutzen können.');

        $deleteForB = $familyB->request('DELETE', "/api/task-lists/{$listId}");
        self::assertFalse($deleteForB['ok']);
        self::assertSame(404, $deleteForB['status']);
    }

    public function testDeletingAListCascadesItsTasks(): void
    {
        $client = $this->loggedInClient();

        $createList = $client->request('POST', '/api/task-lists', ['name' => 'Packliste']);
        $listId = $createList['data']['id'];
        $createTask = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Sonnencreme']);
        $taskId = $createTask['data']['id'];

        $deleteList = $client->request('DELETE', "/api/task-lists/{$listId}");
        self::assertTrue($deleteList['ok']);

        $updateDeletedTask = $client->request('PUT', "/api/tasks/{$taskId}", ['title' => 'sollte nicht mehr existieren']);
        self::assertFalse($updateDeletedTask['ok']);
        self::assertSame(404, $updateDeletedTask['status']);
    }

    public function testDueTasksReturnsOnlyOpenTasksInRangeAcrossLists(): void
    {
        $client = $this->loggedInClient();

        $listA = $client->request('POST', '/api/task-lists', ['name' => 'Liste A']);
        $listB = $client->request('POST', '/api/task-lists', ['name' => 'Liste B']);

        $inRange = $client->request('POST', "/api/task-lists/{$listA['data']['id']}/tasks", [
            'title' => 'Im Zeitraum', 'due_date' => '2026-07-10',
        ]);
        $client->request('POST', "/api/task-lists/{$listB['data']['id']}/tasks", [
            'title' => 'Außerhalb des Zeitraums', 'due_date' => '2026-08-01',
        ]);
        $withoutDueDate = $client->request('POST', "/api/task-lists/{$listA['data']['id']}/tasks", [
            'title' => 'Ohne Fälligkeitsdatum',
        ]);
        $doneInRange = $client->request('POST', "/api/task-lists/{$listA['data']['id']}/tasks", [
            'title' => 'Erledigt im Zeitraum', 'due_date' => '2026-07-15',
        ]);
        $client->request('PUT', "/api/tasks/{$doneInRange['data']['id']}", ['is_done' => true]);

        $due = $client->request('GET', '/api/tasks/due?from=2026-07-01&to=2026-07-31');
        self::assertTrue($due['ok'], json_encode($due));
        self::assertCount(1, $due['data'], 'Nur die offene Aufgabe im Zeitraum darf zurückkommen.');
        self::assertSame('Im Zeitraum', $due['data'][0]['title']);
        self::assertSame('Liste A', $due['data'][0]['task_list_name']);
    }

    public function testDueTasksAreScopedToOwnFamilyOnly(): void
    {
        $familyA = $this->loggedInClient('a@example.test');
        $listA = $familyA->request('POST', '/api/task-lists', ['name' => 'Liste A']);
        $familyA->request('POST', "/api/task-lists/{$listA['data']['id']}/tasks", [
            'title' => 'Aufgabe von Familie A', 'due_date' => '2026-09-01',
        ]);

        $familyB = $this->loggedInClient('b@example.test');
        $dueForB = $familyB->request('GET', '/api/tasks/due?from=2026-09-01&to=2026-09-30');

        self::assertCount(0, $dueForB['data'], 'Familie B darf fällige Aufgaben von Familie A nicht sehen.');
    }
}
