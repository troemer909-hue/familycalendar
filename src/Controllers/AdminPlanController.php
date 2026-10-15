<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;
use PDOException;

/**
 * Plattform-weite Verwaltung der SaaS-Pakete (Tabelle plans) - nicht zu
 * verwechseln mit familieninternen Einstellungen. Nur für
 * TenantContext::isPlatformAdmin() (siehe README, Abschnitt
 * "Plattform-Verwaltung"). Abrechnung selbst (Zahlungsabwicklung) ist
 * weiterhin bewusst außerhalb des MVP - hier wird nur gepflegt, WELCHE
 * Pläne existieren und welche Familie welchem Plan/Status zugeordnet ist
 * (siehe AdminFamilyController).
 */
final class AdminPlanController
{
    /**
     * GET /api/admin/plans
     * Liefert alle Pläne inkl. Anzahl der Familien, die sie aktuell nutzen
     * (wichtig, bevor man einen Plan löscht oder stark verändert).
     */
    public function index(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $stmt = Database::connection()->query(
            'SELECT p.id, p.code, p.name, p.max_members, p.max_calendar_streams, p.price_monthly, p.created_at,
                    COUNT(f.id) AS family_count
             FROM plans p
             LEFT JOIN families f ON f.plan_id = p.id
             GROUP BY p.id
             ORDER BY p.price_monthly ASC, p.name ASC'
        );

        Response::json($stmt->fetchAll());
    }

    /**
     * GET /api/admin/plans/{id}
     */
    public function show(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $plan = $this->findPlan((int) $request->params['id']);
        Response::json($plan);
    }

    /**
     * POST /api/admin/plans
     * Body: { code, name, max_members, max_calendar_streams, price_monthly }
     */
    public function create(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $data = $this->validate($request);

        try {
            $db = Database::connection();
            $db->prepare(
                'INSERT INTO plans (code, name, max_members, max_calendar_streams, price_monthly)
                 VALUES (:code, :name, :max_members, :max_calendar_streams, :price_monthly)'
            )->execute($data);

            Response::json($this->findPlan((int) $db->lastInsertId()), 201);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Dieser Plan-Code wird bereits verwendet.', 409);
            }
            throw $e;
        }
    }

    /**
     * PUT /api/admin/plans/{id}
     * Body: { code, name, max_members, max_calendar_streams, price_monthly }
     */
    public function update(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $id = (int) $request->params['id'];
        $this->findPlan($id); // 404, falls nicht vorhanden
        $data = $this->validate($request);
        $data['id'] = $id;

        try {
            Database::connection()->prepare(
                'UPDATE plans
                 SET code = :code, name = :name, max_members = :max_members,
                     max_calendar_streams = :max_calendar_streams, price_monthly = :price_monthly
                 WHERE id = :id'
            )->execute($data);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Dieser Plan-Code wird bereits verwendet.', 409);
            }
            throw $e;
        }

        Response::json($this->findPlan($id));
    }

    /**
     * DELETE /api/admin/plans/{id}
     * Schlägt bewusst fehl (FOREIGN KEY), solange noch Familien diesen
     * Plan nutzen - verhindert, dass families.plan_id auf einen
     * nicht mehr existierenden Plan verweist.
     */
    public function destroy(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $id = (int) $request->params['id'];
        $this->findPlan($id);

        try {
            Database::connection()->prepare('DELETE FROM plans WHERE id = :id')->execute(['id' => $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Dieser Plan wird noch von mindestens einer Familie genutzt und kann nicht gelöscht werden.', 409);
            }
            throw $e;
        }

        Response::json(['status' => 'deleted']);
    }

    private function validate(Request $request): array
    {
        $code = trim((string) $request->input('code', ''));
        $name = trim((string) $request->input('name', ''));
        $maxMembers = (int) $request->input('max_members', 0);
        $maxCalendarStreams = (int) $request->input('max_calendar_streams', 0);
        $priceMonthly = (float) $request->input('price_monthly', 0);

        if ($code === '' || $name === '' || $maxMembers < 1 || $maxCalendarStreams < 0 || $priceMonthly < 0) {
            Response::error('code, name und max_members (min. 1) sind erforderlich; max_calendar_streams/price_monthly dürfen nicht negativ sein.', 422);
        }

        return [
            'code' => $code,
            'name' => $name,
            'max_members' => $maxMembers,
            'max_calendar_streams' => $maxCalendarStreams,
            'price_monthly' => $priceMonthly,
        ];
    }

    private function findPlan(int $id): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.id, p.code, p.name, p.max_members, p.max_calendar_streams, p.price_monthly, p.created_at,
                    (SELECT COUNT(*) FROM families f WHERE f.plan_id = p.id) AS family_count
             FROM plans p
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $plan = $stmt->fetch();

        if (!$plan) {
            Response::error('Plan nicht gefunden.', 404);
        }

        return $plan;
    }
}
