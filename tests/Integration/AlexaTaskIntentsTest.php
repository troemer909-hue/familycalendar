<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Controllers\AlexaTaskIntentHandler;
use Familienkalender\Core\Database;
use Familienkalender\Core\TenantContext;
use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class AlexaTaskIntentsTest extends TestCase
{
    private PDO $db;
    private int $familyId;
    private int $memberId;

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registered = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Aufgabentest',
            'admin_name' => 'Admin',
            'email' => 'alexa-task-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($registered['ok'], json_encode($registered));
        $client = new \ApiClient(ApiServer::baseUrl(), $registered['data']['token']);
        $members = $client->request('GET', '/api/members');
        self::assertTrue($members['ok'], json_encode($members));
        $this->memberId = (int) $members['data'][0]['id'];
        $stmt = $this->db->prepare('SELECT family_id, user_id FROM family_members WHERE id = :id');
        $stmt->execute(['id' => $this->memberId]);
        $member = $stmt->fetch();
        $this->familyId = (int) $member['family_id'];
        TenantContext::set((int) $member['user_id'], $this->familyId, $this->memberId, 'admin');
    }

    public function testAddTaskUsesTheOnlyListWhenNoListSlotIsGiven(): void
    {
        $listId = $this->insertList('Haushalt');
        $response = $this->invoke('AddTaskIntent', ['title' => ['name' => 'title', 'value' => 'Müll rausbringen']]);

        self::assertStringContainsString('in die Liste Haushalt eingetragen', $response['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT title FROM tasks WHERE task_list_id = :list_id');
        $stmt->execute(['list_id' => $listId]);
        self::assertSame('Müll rausbringen', $stmt->fetchColumn());
    }

    public function testAddTaskAsksWhichListAndAcceptsTheFollowUp(): void
    {
        $this->insertList('Haushalt');
        $shoppingListId = $this->insertList('Einkauf');
        $first = $this->invoke('AddTaskIntent', ['title' => ['name' => 'title', 'value' => 'Milch kaufen']]);
        self::assertSame('list_name', $first['response']['directives'][0]['slotToElicit']);
        self::assertStringContainsString('Haushalt', $first['response']['outputSpeech']['text']);
        self::assertStringContainsString('Einkauf', $first['response']['outputSpeech']['text']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM tasks')->fetchColumn());

        $followUp = $this->invoke('AddTaskIntent', [
            'title' => ['name' => 'title', 'value' => 'Milch kaufen'],
            'list_name' => ['name' => 'list_name', 'value' => 'Einkauf'],
        ]);
        self::assertStringContainsString('eingetragen', $followUp['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT task_list_id FROM tasks WHERE title = :title');
        $stmt->execute(['title' => 'Milch kaufen']);
        self::assertSame($shoppingListId, (int) $stmt->fetchColumn());
    }

    public function testCompleteTaskMarksUniqueOpenTaskAsDone(): void
    {
        $listId = $this->insertList('Haushalt');
        $taskId = $this->insertTask($listId, 'Müll rausbringen');
        $response = $this->invoke('CompleteTaskIntent', ['title' => ['name' => 'title', 'value' => 'Müll rausbringen']]);

        self::assertStringContainsString('abgehakt', $response['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT is_done, done_by FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $taskId]);
        $task = $stmt->fetch();
        self::assertSame(1, (int) $task['is_done']);
        self::assertSame($this->memberId, (int) $task['done_by']);
    }

    public function testAmbiguousCompletionAsksForListAndDoesNotChooseAutomatically(): void
    {
        $household = $this->insertList('Haushalt');
        $shopping = $this->insertList('Einkauf');
        $firstTask = $this->insertTask($household, 'Müll rausbringen');
        $secondTask = $this->insertTask($shopping, 'Müll rausbringen');

        $question = $this->invoke('CompleteTaskIntent', ['title' => ['name' => 'title', 'value' => 'Müll rausbringen']]);
        self::assertSame('list_name', $question['response']['directives'][0]['slotToElicit']);
        self::assertSame(0, (int) $this->db->query('SELECT SUM(is_done) FROM tasks')->fetchColumn());

        $answer = $this->invoke('CompleteTaskIntent', [
            'title' => ['name' => 'title', 'value' => 'Müll rausbringen'],
            'list_name' => ['name' => 'list_name', 'value' => 'Haushalt'],
        ]);
        self::assertStringContainsString('abgehakt', $answer['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT id, is_done FROM tasks ORDER BY id');
        $stmt->execute();
        $tasks = $stmt->fetchAll();
        self::assertSame($firstTask, (int) $tasks[0]['id']);
        self::assertSame(1, (int) $tasks[0]['is_done']);
        self::assertSame($secondTask, (int) $tasks[1]['id']);
        self::assertSame(0, (int) $tasks[1]['is_done']);
    }

    public function testCompleteTaskWithoutMatchReturnsFriendlyResponse(): void
    {
        $this->insertList('Haushalt');
        $response = $this->invoke('CompleteTaskIntent', ['title' => ['name' => 'title', 'value' => 'Nirgendwo']]);
        self::assertStringContainsString('keine offene Aufgabe', $response['response']['outputSpeech']['text']);
    }

    public function testDueTasksReturnsFriendlyEmptyAndListsDueTasksAcrossLists(): void
    {
        $empty = $this->invoke('GetDueTasksIntent', []);
        self::assertStringContainsString('keine Aufgabe fällig', $empty['response']['outputSpeech']['text']);

        $household = $this->insertList('Haushalt');
        $shopping = $this->insertList('Einkauf');
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->insertTask($household, 'Müll rausbringen', $today);
        $this->insertTask($shopping, 'Milch kaufen', $today);
        $this->insertTask($shopping, 'Morgen fällig', $tomorrow);
        $this->insertTask($household, 'Schon erledigt', $today, true);

        $response = $this->invoke('GetDueTasksIntent', []);
        $speech = $response['response']['outputSpeech']['text'];
        self::assertStringContainsString('2 Aufgaben fällig', $speech);
        self::assertStringContainsString('Müll rausbringen in Haushalt', $speech);
        self::assertStringContainsString('Milch kaufen in Einkauf', $speech);
        self::assertStringNotContainsString('Morgen fällig', $speech);
        self::assertStringNotContainsString('Schon erledigt', $speech);
    }

    private function insertList(string $name): int
    {
        $this->db->prepare('INSERT INTO task_lists (family_id, name, created_by) VALUES (:family_id, :name, :member_id)')
            ->execute(['family_id' => $this->familyId, 'name' => $name, 'member_id' => $this->memberId]);
        return (int) $this->db->lastInsertId();
    }

    private function insertTask(int $listId, string $title, ?string $dueDate = null, bool $done = false): int
    {
        $stmt = $this->db->prepare('INSERT INTO tasks (task_list_id, title, due_date, is_done) VALUES (:list_id, :title, :due_date, :is_done)');
        $stmt->execute(['list_id' => $listId, 'title' => $title, 'due_date' => $dueDate, 'is_done' => $done ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    private function invoke(string $intentName, array $slots): array
    {
        return (new AlexaTaskIntentHandler())->handle(['name' => $intentName, 'slots' => $slots]);
    }
}
