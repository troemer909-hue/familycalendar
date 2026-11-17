<?php

declare(strict_types=1);

namespace Familienkalender\Core;

use PDO;

/**
 * Datenbank-Seite der zufälligen Aufgaben-Zuteilung (siehe
 * TaskAssignmentDraw für die reine Lose-Logik): lädt fällige Pools,
 * zieht neu und schreibt das Ergebnis zurück. Läuft NICHT über eine
 * HTTP-Session/TenantContext - wird vom Cron-Job
 * bin/draw-task-assignments.php familienübergreifend aufgerufen, analog
 * zu CalendarSync/bin/sync-calendar-sources.php.
 */
final class TaskAssignmentRunner
{
    /**
     * @return array<int, array{pool_id:int, task_list_id:int, drawn:int, unassigned:int}>
     */
    public static function runDuePools(PDO $db): array
    {
        $pools = $db->query(
            "SELECT tap.*, tl.name AS task_list_name
             FROM task_assignment_pools tap
             JOIN task_lists tl ON tl.id = tap.task_list_id
             WHERE tap.active = 1 AND tap.next_draw_at <= CURDATE()"
        )->fetchAll();

        return array_map(static fn (array $pool): array => self::runPool($db, $pool), $pools);
    }

    /** @return array{pool_id:int, task_list_id:int, drawn:int, unassigned:int} */
    private static function runPool(PDO $db, array $pool): array
    {
        $poolId = (int) $pool['id'];
        $listId = (int) $pool['task_list_id'];

        $membersStmt = $db->prepare(
            'SELECT family_member_id, max_tasks, position FROM task_assignment_pool_members WHERE pool_id = :pool_id ORDER BY position, family_member_id'
        );
        $membersStmt->execute(['pool_id' => $poolId]);
        $members = array_map(static fn (array $r): array => [
            'family_member_id' => (int) $r['family_member_id'],
            'max_tasks' => $r['max_tasks'] !== null ? (int) $r['max_tasks'] : null,
            'position' => (int) $r['position'],
        ], $membersStmt->fetchAll());

        $tasksStmt = $db->prepare(
            'SELECT id, title, assigned_member_id FROM tasks WHERE task_list_id = :list_id AND auto_assign = 1'
        );
        $tasksStmt->execute(['list_id' => $listId]);
        $taskRows = $tasksStmt->fetchAll();

        $tasks = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'last_assigned_member_id' => $r['assigned_member_id'] !== null ? (int) $r['assigned_member_id'] : null,
        ], $taskRows);
        $titleById = array_column($taskRows, 'title', 'id');
        $previousAssigneeById = array_column($taskRows, 'assigned_member_id', 'id');

        $assignments = TaskAssignmentDraw::draw($members, $tasks, $pool['mode']);

        $update = $db->prepare(
            'UPDATE tasks SET assigned_member_id = :member_id, is_done = 0, done_at = NULL, done_by = NULL WHERE id = :id'
        );

        $drawn = 0;
        $unassigned = 0;
        foreach ($assignments as $taskId => $memberId) {
            $update->execute(['member_id' => $memberId, 'id' => $taskId]);

            if ($memberId === null) {
                $unassigned++;
                continue;
            }
            $drawn++;

            $previous = $previousAssigneeById[$taskId] ?? null;
            if ($previous !== null && (int) $previous === $memberId) {
                continue; // dieselbe Person wie zuvor -> keine erneute Benachrichtigung
            }

            self::notify($db, $memberId, $listId, (string) $pool['task_list_name'], (string) $titleById[$taskId]);
        }

        $db->prepare('UPDATE task_assignment_pools SET next_draw_at = :next WHERE id = :id')
            ->execute(['next' => self::advance($pool['rhythm']), 'id' => $poolId]);

        return ['pool_id' => $poolId, 'task_list_id' => $listId, 'drawn' => $drawn, 'unassigned' => $unassigned];
    }

    /**
     * Rechnet immer von "heute" aus weiter (nicht vom alten next_draw_at),
     * damit ein verpasster Cron-Lauf (z.B. Server war tagelang down) genau
     * EINE Nachhol-Ziehung auslöst statt mehrerer aufgestauter Ziehungen
     * auf einen Schlag - einfacher und vorhersehbarer für eine Familie.
     */
    private static function advance(string $rhythm): string
    {
        $modifiers = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'];

        return (new \DateTimeImmutable('today'))->modify($modifiers[$rhythm])->format('Y-m-d');
    }

    /**
     * Push an die neu eingeteilte Person - rein informativ (Best-Effort),
     * analog zu TaskListController::notifyAssignedMember(). Kein eigenes
     * Login (reines Profil) oder Push nicht konfiguriert/kein Abo ->
     * bewusst kein Fehler, siehe PushSender::sendToUser().
     */
    private static function notify(PDO $db, int $memberId, int $listId, string $listName, string $taskTitle): void
    {
        $stmt = $db->prepare('SELECT user_id FROM family_members WHERE id = :id');
        $stmt->execute(['id' => $memberId]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            return;
        }

        try {
            PushSender::sendToUser(
                (int) $userId,
                'Du bist dran: ' . $taskTitle,
                $listName,
                '/task_list_view.php?id=' . $listId
            );
        } catch (\Throwable $e) {
            error_log('Push für Aufgaben-Zuteilung fehlgeschlagen (task_list ' . $listId . '): ' . $e->getMessage());
        }
    }
}
