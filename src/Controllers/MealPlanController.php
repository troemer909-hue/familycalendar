<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

final class MealPlanController
{
    private const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

    public function index(Request $request): void
    {
        $from = (string) ($request->query['from'] ?? '');
        $to = (string) ($request->query['to'] ?? '');
        if (!$this->validDate($from) || !$this->validDate($to) || $from > $to || (strtotime($to) - strtotime($from)) > 62 * 86400) {
            Response::error('Gültiger Datumsbereich erforderlich (max. 63 Tage).', 422);
        }
        $stmt = Database::connection()->prepare('SELECT id, entry_date, meal_slot, label, notes FROM meal_plan_entries WHERE family_id = :family_id AND entry_date BETWEEN :from_date AND :to_date ORDER BY entry_date, FIELD(meal_slot, "breakfast", "lunch", "dinner", "snack")');
        $stmt->execute(['family_id' => TenantContext::familyId(), 'from_date' => $from, 'to_date' => $to]);
        Response::json(array_map(static fn(array $row): array => ['id'=>(int)$row['id'], 'entry_date'=>$row['entry_date'], 'meal_slot'=>$row['meal_slot'], 'label'=>$row['label'], 'notes'=>$row['notes']], $stmt->fetchAll()));
    }

    public function create(Request $request): void { $this->save($request, null); }
    public function update(Request $request): void { $this->save($request, (int)$request->params['id']); }

    private function save(Request $request, ?int $id): void
    {
        $date = (string)$request->input('entry_date', '');
        $slot = (string)$request->input('meal_slot', '');
        $label = trim((string)$request->input('label', ''));
        $notes = trim((string)$request->input('notes', ''));
        if (!$this->validDate($date) || !in_array($slot, self::SLOTS, true) || $label === '' || mb_strlen($label) > 255 || mb_strlen($notes) > 65535) Response::error('Datum, Mahlzeit und Bezeichnung sind erforderlich; Bezeichnung maximal 255 Zeichen.', 422);
        $db = Database::connection();
        try {
            if ($id === null) {
                $db->prepare('INSERT INTO meal_plan_entries (family_id, entry_date, meal_slot, label, notes, created_by_member_id) VALUES (:family_id, :entry_date, :meal_slot, :label, :notes, :member_id)')->execute(['family_id'=>TenantContext::familyId(),'entry_date'=>$date,'meal_slot'=>$slot,'label'=>$label,'notes'=>$notes !== '' ? $notes : null,'member_id'=>TenantContext::familyMemberId()]);
                Response::json(['id'=>(int)$db->lastInsertId()], 201);
            }
            $this->find($db, $id);
            $db->prepare('UPDATE meal_plan_entries SET entry_date=:entry_date, meal_slot=:meal_slot, label=:label, notes=:notes WHERE id=:id AND family_id=:family_id')->execute(['entry_date'=>$date,'meal_slot'=>$slot,'label'=>$label,'notes'=>$notes !== '' ? $notes : null,'id'=>$id,'family_id'=>TenantContext::familyId()]);
            Response::json(['id'=>$id]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '23000') Response::error('Für diesen Tag und diese Mahlzeit gibt es bereits einen Eintrag.', 409);
            throw $e;
        }
    }

    public function destroy(Request $request): void
    {
        $db=Database::connection(); $id=(int)$request->params['id']; $this->find($db,$id);
        $db->prepare('DELETE FROM meal_plan_entries WHERE id=:id AND family_id=:family_id')->execute(['id'=>$id,'family_id'=>TenantContext::familyId()]);
        Response::json(['status'=>'deleted']);
    }

    public function addToShoppingList(Request $request): void
    {
        $db=Database::connection(); $id=(int)$request->params['id']; $entry=$this->find($db,$id);
        $label=trim((string)$request->input('label',''));
        if ($label === '' || mb_strlen($label)>255) Response::error('Zutat ist erforderlich (max. 255 Zeichen).',422);
        $db->prepare('INSERT INTO shopping_list_items (family_id, added_by, label) VALUES (:family_id,:member_id,:label)')->execute(['family_id'=>TenantContext::familyId(),'member_id'=>TenantContext::familyMemberId(),'label'=>$label]);
        Response::json(['id'=>(int)$db->lastInsertId()],201);
    }

    private function find(\PDO $db,int $id): array
    {
        $stmt=$db->prepare('SELECT id FROM meal_plan_entries WHERE id=:id AND family_id=:family_id'); $stmt->execute(['id'=>$id,'family_id'=>TenantContext::familyId()]); $row=$stmt->fetch();
        if (!$row) Response::error('Essensplan-Eintrag nicht gefunden.',404);
        return $row;
    }
    private function validDate(string $date): bool { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date); return $d !== false && $d->format('Y-m-d') === $date; }
}
