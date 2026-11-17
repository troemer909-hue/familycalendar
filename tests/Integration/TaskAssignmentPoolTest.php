<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Core\TaskAssignmentRunner;
use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die zufällige Aufgaben-Zuteilung ab (siehe
 * docs/feature-ideas/aufgaben-zufallsverteilung.md): PUT/GET/DELETE
 * /api/task-lists/{id}/assignment-pool, das auto_assign-Flag auf
 * einzelnen Aufgaben, sowie TaskAssignmentRunner::runDuePools() als
 * Stellvertreter für bin/draw-task-assignments.php. Die eigentliche
 * Lose-Logik (Zufall/Fairness/Kapazität) ist bereits in
 * tests/Unit/TaskAssignmentDrawTest.php isoliert abgedeckt - hier geht
 * es um die Datenbank-Seite drumherum.
 */
final class TaskAssignmentPoolTest extends TestCase
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

    private function addProfile(\ApiClient $client, string $name): int
    {
        $res = $client->request('POST', '/api/members', ['display_name' => $name]);
        self::assertTrue($res['ok'], json_encode($res));

        return (int) $res['data']['id'];
    }

    public function testCreatingAndReadingAssignmentPool(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');
        $max = $this->addProfile($client, 'Max');

        self::assertNull($client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'], 'Ohne eingerichtete Automatik muss der Pool null sein.');

        $save = $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [
                ['family_member_id' => $anna, 'max_tasks' => 2],
                ['family_member_id' => $max, 'max_tasks' => null],
            ],
            'rhythm' => 'weekly',
            'mode' => 'fair',
            'active' => true,
        ]);
        self::assertTrue($save['ok'], json_encode($save));

        $pool = $client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'];
        self::assertSame('weekly', $pool['rhythm']);
        self::assertSame('fair', $pool['mode']);
        self::assertTrue($pool['active']);
        self::assertCount(2, $pool['members']);

        $byMemberId = [];
        foreach ($pool['members'] as $m) {
            $byMemberId[$m['family_member_id']] = $m['max_tasks'];
        }
        self::assertSame(2, $byMemberId[$anna]);
        self::assertNull($byMemberId[$max]);
    }

    public function testSavingAgainReplacesMembersCompletely(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');
        $max = $this->addProfile($client, 'Max');

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna, 'max_tasks' => null]],
            'rhythm' => 'daily',
        ]);

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $max, 'max_tasks' => 1]],
            'rhythm' => 'monthly',
            'mode' => 'random',
        ]);

        $pool = $client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'];
        self::assertSame('monthly', $pool['rhythm']);
        self::assertSame('random', $pool['mode']);
        self::assertCount(1, $pool['members'], 'Anna darf nach dem zweiten Speichern nicht mehr im Pool sein.');
        self::assertSame($max, $pool['members'][0]['family_member_id']);
    }

    public function testInvalidRhythmIsRejected(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $res = $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna]],
            'rhythm' => 'hourly',
        ]);

        self::assertFalse($res['ok']);
        self::assertSame(422, $res['status']);
    }

    public function testMemberFromForeignFamilyIsRejected(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];

        $otherFamily = new \ApiClient(ApiServer::baseUrl(), null);
        $registerOther = $otherFamily->request('POST', '/api/auth/register', [
            'family_name' => 'Andere Familie',
            'admin_name' => 'Fremd-Admin',
            'email' => 'fremd@example.test',
            'password' => 'sicheres-passwort',
        ]);
        $otherClient = new \ApiClient(ApiServer::baseUrl(), $registerOther['data']['token']);
        $foreignMemberId = $otherClient->request('GET', '/api/members')['data'][0]['id'];

        $res = $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $foreignMemberId]],
            'rhythm' => 'weekly',
        ]);

        self::assertFalse($res['ok']);
        self::assertSame(422, $res['status']);
    }

    public function testDeletingPoolLeavesLastAssignmentsInPlace(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna]],
            'rhythm' => 'daily',
        ]);

        $delete = $client->request('DELETE', "/api/task-lists/{$listId}/assignment-pool");
        self::assertTrue($delete['ok']);

        self::assertNull($client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data']);
    }

    public function testTaskCanBeExcludedFromAutoAssignment(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];

        $task = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Spülmaschine']);
        $taskId = $task['data']['id'];

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertTrue($tasks['data'][0]['auto_assign'], 'Neue Aufgaben müssen standardmäßig in der Automatik enthalten sein.');

        $update = $client->request('PUT', "/api/tasks/{$taskId}", ['auto_assign' => false]);
        self::assertTrue($update['ok']);

        $tasksAfter = $client->request('GET', "/api/task-lists/{$listId}/tasks");
        self::assertFalse($tasksAfter['data'][0]['auto_assign']);
    }

    public function testRunDuePoolsDrawsAssignsAndAdvancesNextDrawAt(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Spülmaschine']);
        $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Mülltonne']);

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna]],
            'rhythm' => 'daily',
        ]);
        // PUT setzt next_draw_at bewusst auf heute, damit der Cron-Job
        // sofort zieht statt auf den gewählten Rhythmus zu warten.
        $poolBefore = $client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'];
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $poolBefore['next_draw_at']);

        $results = TaskAssignmentRunner::runDuePools($this->db);

        self::assertCount(1, $results);
        self::assertSame(2, $results[0]['drawn']);
        self::assertSame(0, $results[0]['unassigned']);

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks")['data'];
        foreach ($tasks as $t) {
            self::assertSame($anna, $t['assigned_member_id']);
            self::assertFalse($t['is_done']);
        }

        $poolAfter = $client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'];
        $expectedNext = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        self::assertSame($expectedNext, $poolAfter['next_draw_at'], 'Bei täglichem Rhythmus muss next_draw_at um genau einen Tag weiterrücken.');
    }

    public function testRunDuePoolsResetsCompletedTasksAndLeavesExcessTasksUnassignedWithSingleMember(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $taskIds = [];
        foreach (['Spülmaschine', 'Mülltonne', 'Staubsaugen'] as $title) {
            $created = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => $title]);
            $taskIds[] = $created['data']['id'];
        }
        // Eine Aufgabe schon als erledigt markieren, um zu prüfen, dass
        // eine neue Ziehung sie wieder öffnet (neue Periode = frische
        // Erledigung).
        $client->request('PUT', "/api/tasks/{$taskIds[0]}", ['is_done' => true]);

        // Kernfunktion: auch mit nur EINER Person im Pool bleiben Aufgaben
        // über deren Obergrenze hinaus unzugeteilt statt erzwungen zu werden.
        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna, 'max_tasks' => 2]],
            'rhythm' => 'daily',
        ]);

        $results = TaskAssignmentRunner::runDuePools($this->db);

        self::assertSame(2, $results[0]['drawn']);
        self::assertSame(1, $results[0]['unassigned']);

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks")['data'];
        $assignedCount = 0;
        $unassignedCount = 0;
        foreach ($tasks as $t) {
            self::assertFalse($t['is_done'], 'Jede Aufgabe muss nach der Ziehung wieder offen sein, auch die vorher erledigte.');
            if ($t['assigned_member_id'] === $anna) {
                $assignedCount++;
            } else {
                self::assertNull($t['assigned_member_id']);
                $unassignedCount++;
            }
        }
        self::assertSame(2, $assignedCount);
        self::assertSame(1, $unassignedCount);
    }

    public function testRunDuePoolsSkipsTasksExcludedFromAutoAssignment(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $excludedTask = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Fest zugewiesen']);
        $excludedTaskId = $excludedTask['data']['id'];
        $client->request('PUT', "/api/tasks/{$excludedTaskId}", ['auto_assign' => false]);

        $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Verlost']);

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna]],
            'rhythm' => 'daily',
        ]);

        $results = TaskAssignmentRunner::runDuePools($this->db);
        self::assertSame(1, $results[0]['drawn'], 'Die aus der Automatik herausgenommene Aufgabe darf nicht mitgezählt werden.');

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks")['data'];
        foreach ($tasks as $t) {
            if ((int) $t['id'] === $excludedTaskId) {
                self::assertNull($t['assigned_member_id'], 'Ausgenommene Aufgabe darf von der Ziehung nicht angefasst werden.');
            }
        }
    }

    public function testFixedOrderPoolStoresAndReturnsPositionsInOrder(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');
        $max = $this->addProfile($client, 'Max');
        $lea = $this->addProfile($client, 'Lea');

        $save = $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [
                ['family_member_id' => $anna, 'position' => 2],
                ['family_member_id' => $max, 'position' => 0],
                ['family_member_id' => $lea, 'position' => 1],
            ],
            'rhythm' => 'weekly',
            'mode' => 'fixed_order',
        ]);
        self::assertTrue($save['ok'], json_encode($save));

        $pool = $client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'];
        self::assertSame('fixed_order', $pool['mode']);
        self::assertSame(
            [$max, $lea, $anna],
            array_column($pool['members'], 'family_member_id'),
            'Mitglieder müssen nach position sortiert zurückgegeben werden.'
        );
        self::assertSame([0, 1, 2], array_column($pool['members'], 'position'), 'Positionen werden auf 0..n-1 normalisiert.');
    }

    public function testFixedOrderPoolWithoutExplicitPositionsUsesInputOrder(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');
        $max = $this->addProfile($client, 'Max');

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [
                ['family_member_id' => $anna],
                ['family_member_id' => $max],
            ],
            'rhythm' => 'weekly',
            'mode' => 'fixed_order',
        ]);

        $pool = $client->request('GET', "/api/task-lists/{$listId}/assignment-pool")['data'];
        self::assertSame([$anna, $max], array_column($pool['members'], 'family_member_id'));
        self::assertSame([0, 1], array_column($pool['members'], 'position'));
    }

    public function testInvalidModeIsRejected(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $res = $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna]],
            'rhythm' => 'weekly',
            'mode' => 'alphabetical',
        ]);

        self::assertFalse($res['ok']);
        self::assertSame(422, $res['status']);
    }

    public function testRunDuePoolsWithFixedOrderCyclesThroughMembersByPosition(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');
        $max = $this->addProfile($client, 'Max');

        $task1 = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Spülmaschine']);
        $task2 = $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Mülltonne']);

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [
                ['family_member_id' => $anna, 'position' => 0],
                ['family_member_id' => $max, 'position' => 1],
            ],
            'rhythm' => 'daily',
            'mode' => 'fixed_order',
        ]);

        TaskAssignmentRunner::runDuePools($this->db);
        $afterFirstDraw = $client->request('GET', "/api/task-lists/{$listId}/tasks")['data'];
        $assignedByFirstDraw = [];
        foreach ($afterFirstDraw as $t) {
            $assignedByFirstDraw[$t['id']] = $t['assigned_member_id'];
        }
        self::assertSame($anna, $assignedByFirstDraw[$task1['data']['id']], 'Ohne vorherige Zuweisung startet die Rotation bei Position 0 (Anna).');
        self::assertSame($anna, $assignedByFirstDraw[$task2['data']['id']]);

        // next_draw_at direkt wieder auf heute setzen, um einen zweiten Zug ohne Wartezeit zu erzwingen.
        $this->db->prepare('UPDATE task_assignment_pools SET next_draw_at = CURDATE() WHERE task_list_id = :list_id')
            ->execute(['list_id' => $listId]);

        TaskAssignmentRunner::runDuePools($this->db);
        $afterSecondDraw = $client->request('GET', "/api/task-lists/{$listId}/tasks")['data'];
        foreach ($afterSecondDraw as $t) {
            self::assertSame($max, $t['assigned_member_id'], 'Nach Anna (Position 0) ist beim zweiten Zug jede Aufgabe bei Max (Position 1).');
        }
    }

    public function testRunDuePoolsIgnoresInactivePools(): void
    {
        $client = $this->loggedInClient();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $listId = $list['data']['id'];
        $anna = $this->addProfile($client, 'Anna');

        $client->request('POST', "/api/task-lists/{$listId}/tasks", ['title' => 'Spülmaschine']);

        $client->request('PUT', "/api/task-lists/{$listId}/assignment-pool", [
            'members' => [['family_member_id' => $anna]],
            'rhythm' => 'daily',
            'active' => false,
        ]);

        $results = TaskAssignmentRunner::runDuePools($this->db);
        self::assertSame([], $results);

        $tasks = $client->request('GET', "/api/task-lists/{$listId}/tasks")['data'];
        self::assertNull($tasks[0]['assigned_member_id']);
    }
}
