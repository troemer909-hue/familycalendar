<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\RRule;
use Familienkalender\Core\TenantContext;

/**
 * Geteilte Aufgaben-/Einkaufslisten pro Familie (siehe
 * docs/aufgabenlisten-konzept.md). Bewusst OHNE Plan-Gating (anders als
 * CategoryController) - das ist eine Kernfunktion eines Familienplaners,
 * keine, die man auf "plus" beschränken will.
 */
final class TaskListController
{
    /**
     * GET /api/task-lists
     * Listen der Familie inkl. offener/gesamt Aufgabenzahl fürs
     * Übersichts-Karten-Layout.
     */
    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT tl.id, tl.name, tl.created_at,
                    COUNT(t.id) AS total_count,
                    COUNT(t.id) - SUM(t.is_done) AS open_count
             FROM task_lists tl
             LEFT JOIN tasks t ON t.task_list_id = tl.id
             WHERE tl.family_id = :family_id
             GROUP BY tl.id, tl.name, tl.created_at
             ORDER BY tl.created_at ASC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        $lists = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'total_count' => (int) $row['total_count'],
            'open_count' => (int) $row['open_count'],
        ], $stmt->fetchAll());

        Response::json($lists);
    }

    /**
     * GET /api/tasks/due?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Offene (nicht erledigte) Aufgaben mit Fälligkeitsdatum im Zeitraum,
     * familienweit über alle Listen hinweg - für die Tagesmarkierungen im
     * Kalender (siehe calendar.php, Phase 4 im Konzept). Bewusst nur
     * offene Aufgaben: erledigte sollen den Kalender nicht zumüllen.
     */
    public function dueTasks(Request $request): void
    {
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            Response::error('from und to sind erforderlich (Format YYYY-MM-DD).', 422);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT t.id, t.title, t.due_date, t.task_list_id, tl.name AS task_list_name
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.task_list_id
             WHERE tl.family_id = :family_id AND t.is_done = 0
                   AND t.due_date BETWEEN :from AND :to
             ORDER BY t.due_date ASC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId(), 'from' => $from, 'to' => $to]);

        $tasks = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'due_date' => $row['due_date'],
            'task_list_id' => (int) $row['task_list_id'],
            'task_list_name' => $row['task_list_name'],
        ], $stmt->fetchAll());

        Response::json($tasks);
    }

    /**
     * POST /api/task-lists
     * Body: { name }
     */
    public function create(Request $request): void
    {
        $db = Database::connection();
        $name = trim((string) $request->input('name', ''));

        if ($name === '' || mb_strlen($name) > 120) {
            Response::error('name ist erforderlich (max. 120 Zeichen).', 422);
        }

        $db->prepare('INSERT INTO task_lists (family_id, name, created_by) VALUES (:family_id, :name, :created_by)')
            ->execute([
                'family_id' => TenantContext::familyId(),
                'name' => $name,
                'created_by' => TenantContext::familyMemberId(),
            ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'name' => $name], 201);
    }

    /**
     * DELETE /api/task-lists/{id}
     * Löscht die Liste inkl. aller Aufgaben (ON DELETE CASCADE).
     */
    public function destroy(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $this->findOwnList($db, $id);

        $db->prepare('DELETE FROM task_lists WHERE id = :id')->execute(['id' => $id]);

        Response::json(['status' => 'deleted']);
    }

    /**
     * GET /api/task-lists/{id}/tasks
     * Offene Aufgaben zuerst (nach Erstellungsreihenfolge), erledigte
     * danach (nach Erledigungszeitpunkt, neueste zuerst) - sonst würde
     * eine lange Liste erledigter Punkte die noch offenen nach unten
     * verdrängen.
     */
    public function tasks(Request $request): void
    {
        $db = Database::connection();
        $listId = (int) $request->params['id'];
        $this->findOwnList($db, $listId);

        $stmt = $db->prepare(
            'SELECT t.id, t.title, t.due_date, t.is_done, t.done_at,
                    t.assigned_member_id, fm.display_name AS assigned_member_name
             FROM tasks t
             LEFT JOIN family_members fm ON fm.id = t.assigned_member_id
             WHERE t.task_list_id = :list_id
             ORDER BY t.is_done ASC, t.is_done = 1 AND t.done_at IS NOT NULL DESC, t.done_at DESC, t.created_at ASC'
        );
        $stmt->execute(['list_id' => $listId]);

        $tasks = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'due_date' => $row['due_date'],
            'is_done' => (bool) $row['is_done'],
            'assigned_member_id' => $row['assigned_member_id'] !== null ? (int) $row['assigned_member_id'] : null,
            'assigned_member_name' => $row['assigned_member_name'],
        ], $stmt->fetchAll());

        Response::json($tasks);
    }

    /**
     * POST /api/task-lists/{id}/tasks
     * Body: { title, assigned_member_id?, due_date?, recurrence_rule? }
     *
     * recurrence_rule (optional) erzeugt mehrere unabhängige Aufgaben auf
     * einmal (z.B. "jeden Montag Müll rausbringen") - wiederverwendet
     * dieselbe RRule-Klasse wie EventController::create(), aber OHNE
     * dessen Serien-Tracking (recurrence_parent_id/-rule): jede erzeugte
     * Aufgabe ist eine ganz normale, eigenständige Zeile, einzeln
     * umbenennbar/verschiebbar/löschbar, ohne Rückwirkung auf die
     * "Geschwister". Für Aufgaben reicht das - anders als bei Terminen
     * gibt es hier keinen Bedarf, eine ganze Serie nachträglich auf einen
     * Schlag zu verschieben.
     */
    public function createTask(Request $request): void
    {
        $db = Database::connection();
        $listId = (int) $request->params['id'];
        $this->findOwnList($db, $listId);

        $title = trim((string) $request->input('title', ''));
        if ($title === '' || mb_strlen($title) > 255) {
            Response::error('title ist erforderlich (max. 255 Zeichen).', 422);
        }

        $assignedMemberId = $this->sanitizeAssignedMember($db, $request->input('assigned_member_id'));
        $dueDate = $this->sanitizeDueDate($request->input('due_date'));
        $recurrenceRule = trim((string) $request->input('recurrence_rule', '')) ?: null;

        $dueDates = [$dueDate];
        if ($recurrenceRule !== null) {
            if ($dueDate === null) {
                Response::error('Eine Wiederholung braucht ein Fälligkeitsdatum.', 422);
            }

            try {
                $start = new \DateTimeImmutable($dueDate);
                $occurrences = RRule::expand($recurrenceRule, $start, $start);
            } catch (\Throwable $e) {
                Response::error('Wiederholung konnte nicht verarbeitet werden: ' . $e->getMessage(), 422);
            }

            $dueDates = array_map(static fn (array $o): string => $o['starts_at']->format('Y-m-d'), $occurrences);
        }

        $insert = $db->prepare(
            'INSERT INTO tasks (task_list_id, title, assigned_member_id, due_date)
             VALUES (:task_list_id, :title, :assigned_member_id, :due_date)'
        );

        $firstId = null;
        foreach ($dueDates as $occurrenceDueDate) {
            $insert->execute([
                'task_list_id' => $listId,
                'title' => $title,
                'assigned_member_id' => $assignedMemberId,
                'due_date' => $occurrenceDueDate,
            ]);
            $firstId ??= (int) $db->lastInsertId();
        }

        Response::json(['id' => $firstId, 'occurrences' => count($dueDates)], 201);
    }

    /**
     * PUT /api/tasks/{id}
     * Body: { title?, assigned_member_id?, due_date?, is_done? } - deckt
     * sowohl "normales" Bearbeiten als auch das Abhaken/Zurücksetzen ab
     * (ein Toggle-Button sendet einfach nur is_done mit), analog zum
     * PUT-Muster bei events/categories statt eines eigenen PATCH.
     */
    public function updateTask(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $task = $this->findOwnTask($db, $id);

        $title = trim((string) $request->input('title', $task['title']));
        if ($title === '' || mb_strlen($title) > 255) {
            Response::error('title darf nicht leer sein (max. 255 Zeichen).', 422);
        }

        $assignedMemberId = array_key_exists('assigned_member_id', $request->body)
            ? $this->sanitizeAssignedMember($db, $request->input('assigned_member_id'))
            : $task['assigned_member_id'];

        $dueDate = array_key_exists('due_date', $request->body)
            ? $this->sanitizeDueDate($request->input('due_date'))
            : $task['due_date'];

        $isDone = $request->body['is_done'] ?? (bool) $task['is_done'];
        $isDone = (bool) $isDone;

        $doneAt = $isDone ? ($task['is_done'] ? $task['done_at'] : date('Y-m-d H:i:s')) : null;
        $doneBy = $isDone ? ($task['is_done'] ? $task['done_by'] : TenantContext::familyMemberId()) : null;

        $db->prepare(
            'UPDATE tasks
             SET title = :title, assigned_member_id = :assigned_member_id, due_date = :due_date,
                 is_done = :is_done, done_at = :done_at, done_by = :done_by
             WHERE id = :id'
        )->execute([
            'title' => $title,
            'assigned_member_id' => $assignedMemberId,
            'due_date' => $dueDate,
            'is_done' => $isDone ? 1 : 0,
            'done_at' => $doneAt,
            'done_by' => $doneBy,
            'id' => $id,
        ]);

        Response::json(['id' => $id, 'is_done' => $isDone]);
    }

    /**
     * DELETE /api/tasks/{id}
     */
    public function deleteTask(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $this->findOwnTask($db, $id);

        $db->prepare('DELETE FROM tasks WHERE id = :id')->execute(['id' => $id]);

        Response::json(['status' => 'deleted']);
    }

    /** @return array{id:int,name:string} */
    private function findOwnList(\PDO $db, int $id): array
    {
        $stmt = $db->prepare('SELECT id, name FROM task_lists WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        $list = $stmt->fetch();

        if (!$list) {
            Response::error('Liste nicht gefunden.', 404);
        }

        return $list;
    }

    /**
     * Mandantentrennung über einen JOIN auf task_lists statt einer
     * eigenen family_id-Spalte auf tasks selbst - eine Aufgabe gehört
     * immer zu genau einer Liste, deren family_id ist bereits die
     * maßgebliche Quelle.
     *
     * @return array{id:int,title:string,assigned_member_id:?int,due_date:?string,is_done:int,done_at:?string,done_by:?int}
     */
    private function findOwnTask(\PDO $db, int $id): array
    {
        $stmt = $db->prepare(
            'SELECT t.id, t.title, t.assigned_member_id, t.due_date, t.is_done, t.done_at, t.done_by
             FROM tasks t
             JOIN task_lists tl ON tl.id = t.task_list_id
             WHERE t.id = :id AND tl.family_id = :family_id'
        );
        $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        $task = $stmt->fetch();

        if (!$task) {
            Response::error('Aufgabe nicht gefunden.', 404);
        }

        return $task;
    }

    /** Leerstring/0/null -> NULL, sonst muss die Mitglieds-ID zur eigenen Familie gehören. */
    private function sanitizeAssignedMember(\PDO $db, mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        $stmt = $db->prepare('SELECT id FROM family_members WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => (int) $value, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) {
            Response::error('assigned_member_id gehört nicht zu dieser Familie.', 422);
        }

        return (int) $value;
    }

    /** Leerstring/null -> NULL, sonst Format YYYY-MM-DD erzwingen. */
    private function sanitizeDueDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            Response::error('due_date muss im Format YYYY-MM-DD sein.', 422);
        }

        return $value;
    }
}
