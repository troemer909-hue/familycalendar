<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

final class MealPlanController
{
    public function index(Request $request): void
    {
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        if (!$this->validDate($from) || !$this->validDate($to) || $from > $to || (strtotime($to) - strtotime($from)) > 31 * 86400) Response::error('from und to müssen einen gültigen Zeitraum von höchstens 32 Tagen bilden.', 422);
        $stmt = Database::connection()->prepare('SELECT id, entry_date, meal_slot, label, notes, created_by_member_id, created_at FROM meal_plan_entries WHERE family_id=:family_id AND entry_date BETWEEN :from_date AND :to_date ORDER BY entry_date, FIELD(meal_slot, \'breakfast\',\'lunch\',\'dinner\',\'snack\')');
        $stmt->execute(['family_id' => TenantContext::familyId(), 'from_date' => $from, 'to_date' => $to]);
        $rows = array_map(static function (array $r): array { $r['id'] = (int) $r['id']; $r['created_by_member_id'] = $r['created_by_member_id'] !== null ? (int) $r['created_by_member_id'] : null; return $r; }, $stmt->fetchAll());
        Response::json($rows);
    }

    public function create(Request $request): void
    {
        $data = $this->validated($request); $db = Database::connection();
        try { $stmt = $db->prepare('INSERT INTO meal_plan_entries (family_id, entry_date, meal_slot, label, notes, created_by_member_id) VALUES (:family_id, :entry_date, :meal_slot, :label, :notes, :member_id)'); $stmt->execute($data + ['family_id' => TenantContext::familyId(), 'member_id' => TenantContext::familyMemberId()]); }
        catch (\PDOException $e) { if ((string) $e->getCode() === '23000') Response::error('Für diesen Tag und diese Mahlzeit gibt es bereits einen Eintrag.', 409); throw $e; }
        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function update(Request $request): void
    {
        $id = (int) $request->params['id']; $data = $this->validated($request); $db = Database::connection(); $this->findOwn($db, $id);
        try { $stmt = $db->prepare('UPDATE meal_plan_entries SET entry_date=:entry_date, meal_slot=:meal_slot, label=:label, notes=:notes WHERE id=:id'); $stmt->execute($data + ['id' => $id]); }
        catch (\PDOException $e) { if ((string) $e->getCode() === '23000') Response::error('Für diesen Tag und diese Mahlzeit gibt es bereits einen Eintrag.', 409); throw $e; }
        Response::json(['id' => $id, 'status' => 'updated']);
    }

    public function destroy(Request $request): void
    {
        $db = Database::connection(); $id = (int) $request->params['id']; $this->findOwn($db, $id);
        $db->prepare('DELETE FROM meal_plan_entries WHERE id=:id')->execute(['id' => $id]); Response::json(['status' => 'deleted']);
    }

    public function addToShoppingList(Request $request): void
    {
        $db = Database::connection(); $this->findOwn($db, (int) $request->params['id']);
        $label = trim((string) $request->input('label', ''));
        if ($label === '' || mb_strlen($label) > 255) Response::error('label ist erforderlich (max. 255 Zeichen).', 422);
        $db->prepare('INSERT INTO shopping_list_items (family_id, added_by, label) VALUES (:family_id, :added_by, :label)')->execute(['family_id' => TenantContext::familyId(), 'added_by' => TenantContext::familyMemberId(), 'label' => $label]);
        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    private function validated(Request $request): array
    {
        $date = trim((string) $request->input('entry_date', '')); $slot = (string) $request->input('meal_slot', ''); $label = trim((string) $request->input('label', '')); $notes = trim((string) $request->input('notes', ''));
        if (!$this->validDate($date)) Response::error('entry_date muss ein gültiges Datum sein.', 422);
        if (!in_array($slot, ['breakfast', 'lunch', 'dinner', 'snack'], true)) Response::error('Ungültige Mahlzeit.', 422);
        if ($label === '' || mb_strlen($label) > 255) Response::error('label ist erforderlich (max. 255 Zeichen).', 422);
        return ['entry_date' => $date, 'meal_slot' => $slot, 'label' => $label, 'notes' => $notes !== '' ? $notes : null];
    }
    private function validDate(string $date): bool { $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $d !== false && $d->format('Y-m-d') === $date; }
    private function findOwn(\PDO $db, int $id): void
    {
        $stmt = $db->prepare('SELECT id FROM meal_plan_entries WHERE id=:id AND family_id=:family_id'); $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) Response::error('Eintrag nicht gefunden.', 404);
    }
}
