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
}
