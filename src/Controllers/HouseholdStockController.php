<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Vorrat-Tracker / "Wird knapp"-Liste (siehe
 * docs/feature-ideas/vorrat-nachbestellen.md) - dauerhafte Liste
 * wiederkehrender Haushaltsartikel, getrennt von der Einkaufsliste
 * (shopping_list_items), die nach jedem Einkauf geleert wird. "Wird
 * knapp" erzeugt serverseitig einen Einkaufslisten-Eintrag; das spätere
 * Abhaken dieses Eintrags setzt low_stock hier automatisch wieder
 * zurück (siehe ShoppingListController::update()).
 */
final class HouseholdStockController
{
    /**
     * GET /api/household-stock
     */
    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, label, low_stock, flagged_at
             FROM household_stock_items
             WHERE family_id = :family_id
             ORDER BY label ASC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'low_stock' => (bool) $row['low_stock'],
            'flagged_at' => $row['flagged_at'],
        ], $stmt->fetchAll());

        Response::json($items);
    }

    /**
     * POST /api/household-stock
     * Body: { label }
     * Legt einen neuen dauerhaften Vorratsartikel an - eindeutig je
     * Familie (siehe uniq_family_label), damit nicht aus Versehen
     * derselbe Artikel mehrfach gepflegt wird.
     */
    public function create(Request $request): void
    {
        $label = trim((string) $request->input('label', ''));
        if ($label === '' || mb_strlen($label) > 255) {
            Response::error('label ist erforderlich (max. 255 Zeichen).', 422);
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM household_stock_items WHERE family_id = :family_id AND label = :label');
        $stmt->execute(['family_id' => TenantContext::familyId(), 'label' => $label]);
        if ($stmt->fetch()) {
            Response::error('Dieser Artikel ist bereits in der Vorratsliste.', 422);
        }

        $db->prepare(
            'INSERT INTO household_stock_items (family_id, label) VALUES (:family_id, :label)'
        )->execute(['family_id' => TenantContext::familyId(), 'label' => $label]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    /**
     * POST /api/household-stock/{id}/flag-low
     * Markiert den Artikel als "wird knapp" und erzeugt automatisch
     * einen Einkaufslisten-Eintrag dafür - bereits gemeldete Artikel
     * erzeugen keinen zweiten Einkaufslisten-Eintrag (sonst würde
     * mehrfaches Antippen den Artikel mehrfach auf die Einkaufsliste
     * setzen).
     */
    public function flagLow(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $item = $this->findOwnItem($db, $id);

        if ($item['low_stock']) {
            Response::json(['id' => $id, 'low_stock' => true]);
            return;
        }

        $db->prepare(
            'UPDATE household_stock_items
             SET low_stock = 1, flagged_by = :flagged_by, flagged_at = NOW()
             WHERE id = :id'
        )->execute(['flagged_by' => TenantContext::familyMemberId(), 'id' => $id]);

        $db->prepare(
            'INSERT INTO shopping_list_items (family_id, added_by, label, source_stock_item_id)
             VALUES (:family_id, :added_by, :label, :source_stock_item_id)'
        )->execute([
            'family_id' => TenantContext::familyId(),
            'added_by' => TenantContext::familyMemberId(),
            'label' => $item['label'],
            'source_stock_item_id' => $id,
        ]);

        Response::json(['id' => $id, 'low_stock' => true]);
    }

    /**
     * DELETE /api/household-stock/{id}
     * Entfernt den Artikel dauerhaft aus der Vorratsliste. Ein bereits
     * erzeugter Einkaufslisten-Eintrag bleibt erhalten (FK ON DELETE SET
     * NULL) - er verliert nur die Rückkopplung zur Vorratsliste.
     */
    public function destroy(Request $request): void
    {
        $db = Database::connection();
        $id = (int) $request->params['id'];
        $this->findOwnItem($db, $id);

        $db->prepare('DELETE FROM household_stock_items WHERE id = :id')->execute(['id' => $id]);

        Response::json(['status' => 'deleted']);
    }

    /** @return array{id:int,label:string,low_stock:int} */
    private function findOwnItem(\PDO $db, int $id): array
    {
        $stmt = $db->prepare(
            'SELECT id, label, low_stock FROM household_stock_items WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        $item = $stmt->fetch();
        if (!$item) {
            Response::error('Artikel nicht gefunden.', 404);
        }

        return $item;
    }
}
