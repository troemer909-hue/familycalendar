<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Gemeinsame Einkaufsliste pro Familie (siehe
 * docs/feature-ideas/einkaufsliste.md) - genau EINE Liste pro Familie
 * (kein Multi-Listen-Management wie bei den Aufgabenlisten), jedes
 * Mitglied darf alles (keine Admin-Beschränkung, analog zu
 * TaskListController). Abgehakte Artikel bleiben (ausgegraut) sichtbar,
 * bis "Liste leeren" sie entfernt - so sieht man im Laden noch, was
 * schon im Wagen liegt.
 */
final class ShoppingListController
{
    /**
     * GET /api/shopping-list
     * Offene Artikel zuerst (nach Anlage-Reihenfolge), abgehakte danach
     * (neueste zuerst) - gleiches Sortierprinzip wie
     * TaskListController::tasks().
     */
    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT si.id, si.label, si.checked, si.checked_at,
                    added.display_name AS added_by_name,
                    checker.display_name AS checked_by_name
             FROM shopping_list_items si
             LEFT JOIN family_members added ON added.id = si.added_by
             LEFT JOIN family_members checker ON checker.id = si.checked_by
             WHERE si.family_id = :family_id
             ORDER BY si.checked ASC, si.checked_at DESC, si.created_at ASC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'checked' => (bool) $row['checked'],
            'checked_at' => $row['checked_at'],
            'added_by_name' => $row['added_by_name'],
            'checked_by_name' => $row['checked_by_name'],
        ], $stmt->fetchAll());

        Response::json($items);
    }

    /**
     * POST /api/shopping-list
     * Body: { label }
     */
    public function create(Request $request): void
    {
        $label = trim((string) $request->input('label', ''));
        if ($label === '' || mb_strlen($label) > 255) {
            Response::error('label ist erforderlich (max. 255 Zeichen).', 422);
        }

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO shopping_list_items (family_id, added_by, label)
             VALUES (:family_id, :added_by, :label)'
        )->execute([
            'family_id' => TenantContext::familyId(),
            'added_by' => TenantContext::familyMemberId(),
            'label' => $label,
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    /**
     * PUT /api/shopping-list/{id}
     * Body: { checked }
     * Abhaken/Zurücksetzen - ein Toggle-Button sendet einfach den
     * gewünschten Zielwert mit, analog zu
     * TaskListController::updateTask().
     */
    public function update(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $this->findOwnItem($db, $id);

        $checked = (bool) $request->input('checked', false);

        $db->prepare(
            'UPDATE shopping_list_items
             SET checked = :checked, checked_by = :checked_by, checked_at = :checked_at
             WHERE id = :id'
        )->execute([
            'checked' => $checked ? 1 : 0,
            'checked_by' => $checked ? TenantContext::familyMemberId() : null,
            'checked_at' => $checked ? date('Y-m-d H:i:s') : null,
            'id' => $id,
        ]);

        Response::json(['id' => $id, 'checked' => $checked]);
    }

    /**
     * DELETE /api/shopping-list/{id}
     */
    public function destroy(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $this->findOwnItem($db, $id);

        $db->prepare('DELETE FROM shopping_list_items WHERE id = :id')->execute(['id' => $id]);

        Response::json(['status' => 'deleted']);
    }

    /**
     * POST /api/shopping-list/clear-checked
     * Entfernt alle abgehakten Artikel auf einmal (nach dem Einkauf) -
     * offene Artikel bleiben unangetastet.
     */
    public function clearChecked(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM shopping_list_items WHERE family_id = :family_id AND checked = 1');
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        Response::json(['status' => 'cleared', 'removed' => $stmt->rowCount()]);
    }

    private function findOwnItem(\PDO $db, int $id): void
    {
        $stmt = $db->prepare('SELECT id FROM shopping_list_items WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) {
            Response::error('Artikel nicht gefunden.', 404);
        }
    }
}
