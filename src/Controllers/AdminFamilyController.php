<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Plattform-weite Verwaltung von Plan-Zuordnung und Abrechnungsstatus
 * JEDER Familie - nur für TenantContext::isPlatformAdmin(). Reine
 * Status-Pflege (kein Zahlungsabwicklungs-Flow, siehe
 * AdminPlanController), entspricht dem bereits in schema.sql
 * dokumentierten Ansatz "Familien starten manuell im Trial".
 */
final class AdminFamilyController
{
    private const STATUSES = ['trial', 'active', 'suspended', 'cancelled'];

    /**
     * GET /api/admin/families?status=trial
     */
    public function index(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $status = $request->query['status'] ?? '';
        $sql = 'SELECT f.id, f.name, f.status, f.plan_id, p.code AS plan_code, p.name AS plan_name,
                       (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id) AS member_count,
                       f.created_at
                FROM families f
                LEFT JOIN plans p ON p.id = f.plan_id';
        $params = [];

        if (in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE f.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY f.created_at DESC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        Response::json($stmt->fetchAll());
    }

    /**
     * PUT /api/admin/families/{id}
     * Body: { plan_id: int|null, status }
     */
    public function update(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $id = (int) $request->params['id'];
        $db = Database::connection();

        $exists = $db->prepare('SELECT id FROM families WHERE id = :id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetch()) {
            Response::error('Familie nicht gefunden.', 404);
        }

        $status = (string) $request->input('status', '');
        if (!in_array($status, self::STATUSES, true)) {
            Response::error('status muss einer von: ' . implode(', ', self::STATUSES) . ' sein.', 422);
        }

        $planId = $request->input('plan_id');
        $planId = ($planId === null || $planId === '') ? null : (int) $planId;

        if ($planId !== null) {
            $planExists = $db->prepare('SELECT id FROM plans WHERE id = :id');
            $planExists->execute(['id' => $planId]);
            if (!$planExists->fetch()) {
                Response::error('Dieser Plan existiert nicht.', 422);
            }
        }

        $db->prepare('UPDATE families SET plan_id = :plan_id, status = :status WHERE id = :id')
            ->execute(['plan_id' => $planId, 'status' => $status, 'id' => $id]);

        $stmt = $db->prepare(
            'SELECT f.id, f.name, f.status, f.plan_id, p.code AS plan_code, p.name AS plan_name,
                    (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id) AS member_count,
                    f.created_at
             FROM families f
             LEFT JOIN plans p ON p.id = f.plan_id
             WHERE f.id = :id'
        );
        $stmt->execute(['id' => $id]);

        Response::json($stmt->fetch());
    }
}
