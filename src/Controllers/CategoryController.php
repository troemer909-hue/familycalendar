<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Termin-Kategorien: Familien im "plus"-Plan verwalten ihre eigenen
 * (categories-Tabelle), Familien im "standard"-Plan (Free) bekommen eine
 * feste, serverseitig definierte Auswahlliste ohne eigene Verwaltung -
 * GET /api/categories liefert in beiden Fällen dieselbe Form zurück
 * ({ id, name, color, custom }), damit event_form.php nicht wissen muss,
 * welcher Plan gerade aktiv ist. events.category bleibt dabei bewusst ein
 * reines Freitextfeld (siehe database/schema.sql) - die hier verwalteten
 * Kategorien sind nur die Quelle für das Auswahl-Dropdown im Termin-
 * Formular, keine feste Fremdschlüssel-Beziehung.
 */
final class CategoryController
{
    /**
     * Generische Standardkategorien für Familien ohne "plus"-Plan - bewusst
     * ohne eigene DB-Zeilen (gilt für ALLE Standard-Familien gleich, keine
     * Verwaltung nötig). id ist hier der Kategorie-NAME selbst (stabil,
     * nie durch eine Migration verschoben) statt einer numerischen ID.
     */
    private const DEFAULT_CATEGORIES = [
        ['name' => 'Familie', 'color' => '#203650'],
        ['name' => 'Schule', 'color' => '#238fa8'],
        ['name' => 'Sport', 'color' => '#29b5bd'],
        ['name' => 'Arzt', 'color' => '#ef3b59'],
        ['name' => 'Haushalt', 'color' => '#f2763b'],
        ['name' => 'Freizeit', 'color' => '#ff9d28'],
        ['name' => 'Sonstiges', 'color' => '#94a3b8'],
    ];

    /**
     * GET /api/categories
     * Liefert die Liste fürs Auswahl-Dropdown im Termin-Formular - bei
     * "plus" die eigenen, frei verwalteten Kategorien der Familie, sonst
     * die feste Standardliste.
     */
    public function index(Request $request): void
    {
        $db = Database::connection();
        $isPlus = $this->familyPlanCode($db) === 'plus';

        if (!$isPlus) {
            Response::json([
                'editable' => false,
                'categories' => array_map(
                    static fn (array $c): array => ['id' => $c['name'], 'name' => $c['name'], 'color' => $c['color']],
                    self::DEFAULT_CATEGORIES
                ),
            ]);
        }

        $stmt = $db->prepare('SELECT id, name, color FROM categories WHERE family_id = :family_id ORDER BY name ASC');
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        Response::json([
            'editable' => true,
            'categories' => array_map(
                static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'color' => $row['color']],
                $stmt->fetchAll()
            ),
        ]);
    }

    /**
     * POST /api/categories
     * Body: { name, color? } - nur "plus".
     */
    public function create(Request $request): void
    {
        $db = Database::connection();
        $this->requirePlusPlan($db);

        $name = trim((string) $request->input('name', ''));
        $color = $this->sanitizeColor((string) $request->input('color', '#64748b'));

        if ($name === '' || mb_strlen($name) > 50) {
            Response::error('name ist erforderlich (max. 50 Zeichen).', 422);
        }

        $stmt = $db->prepare('SELECT id FROM categories WHERE family_id = :family_id AND name = :name');
        $stmt->execute(['family_id' => TenantContext::familyId(), 'name' => $name]);
        if ($stmt->fetch()) {
            Response::error('Diese Kategorie gibt es bereits.', 409);
        }

        $db->prepare('INSERT INTO categories (family_id, name, color) VALUES (:family_id, :name, :color)')
            ->execute(['family_id' => TenantContext::familyId(), 'name' => $name, 'color' => $color]);

        Response::json(['id' => (int) $db->lastInsertId(), 'name' => $name, 'color' => $color, 'custom' => true], 201);
    }

    /**
     * PUT /api/categories/{id}
     * Body: { name?, color? } - nur "plus".
     */
    public function update(Request $request): void
    {
        $db = Database::connection();
        $this->requirePlusPlan($db);

        $id = (int) $request->params['id'];
        $category = $this->findOwnCategory($db, $id);

        $name = trim((string) $request->input('name', $category['name']));
        $color = $this->sanitizeColor((string) $request->input('color', $category['color']));

        if ($name === '' || mb_strlen($name) > 50) {
            Response::error('name darf nicht leer sein (max. 50 Zeichen).', 422);
        }

        $dupStmt = $db->prepare('SELECT id FROM categories WHERE family_id = :family_id AND name = :name AND id != :id');
        $dupStmt->execute(['family_id' => TenantContext::familyId(), 'name' => $name, 'id' => $id]);
        if ($dupStmt->fetch()) {
            Response::error('Diese Kategorie gibt es bereits.', 409);
        }

        $db->prepare('UPDATE categories SET name = :name, color = :color WHERE id = :id')
            ->execute(['name' => $name, 'color' => $color, 'id' => $id]);

        Response::json(['id' => $id, 'name' => $name, 'color' => $color, 'custom' => true]);
    }

    /**
     * DELETE /api/categories/{id}
     * Nur "plus". Termine, die diese Kategorie bereits tragen, behalten
     * das Text-Label unverändert (siehe Klassendoc - keine Fremdschlüssel-
     * Beziehung), sie verschwindet nur aus dem Auswahl-Dropdown.
     */
    public function destroy(Request $request): void
    {
        $db = Database::connection();
        $this->requirePlusPlan($db);

        $id = (int) $request->params['id'];
        $this->findOwnCategory($db, $id);

        $db->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $id]);

        Response::json(['status' => 'deleted']);
    }

    private function familyPlanCode(\PDO $db): string
    {
        $stmt = $db->prepare(
            'SELECT p.code FROM families f JOIN plans p ON p.id = f.plan_id WHERE f.id = :family_id'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        return (string) ($stmt->fetchColumn() ?: 'standard');
    }

    private function requirePlusPlan(\PDO $db): void
    {
        if ($this->familyPlanCode($db) !== 'plus') {
            Response::error('Frei verwaltbare Kategorien sind ein Plus-Feature.', 403);
        }
    }

    /** @return array{id:int,name:string,color:string} */
    private function findOwnCategory(\PDO $db, int $id): array
    {
        $stmt = $db->prepare('SELECT id, name, color FROM categories WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        $category = $stmt->fetch();

        if (!$category) {
            Response::error('Kategorie nicht gefunden.', 404);
        }

        return $category;
    }

    /** Nur #rrggbb/#rgb durchlassen, sonst Default - kein Freitext in einem CSS-Farbwert. */
    private function sanitizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) ? $color : '#64748b';
    }
}
