<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

final class MemberPointsController
{
    public function index(Request $request): void
    {
        $db = Database::connection();
        $targetMemberId = $request->input('family_member_id');

        if ($targetMemberId !== null && $targetMemberId !== '') {
            $mid = (int) $targetMemberId;
            $this->assertOwnOrAdminMember($db, $mid);

            $row = $db->prepare(
                'SELECT mpf.id AS member_id, mpf.display_name,' . "\n" .
                '       COALESCE(SUM(mpl.points), 0) AS balance' . "\n" .
                '  FROM family_members mpf' . "\n" .
                '  LEFT JOIN member_points_ledger mpl' . "\n" .
                '    ON mpl.family_member_id = mpf.id AND mpl.task_id IS NOT NULL' . "\n" .
                ' WHERE mpf.id = :id AND mpf.family_id = :family_id' . "\n" .
                ' GROUP BY mpf.id, mpf.display_name'
            )->fetch(['id' => $mid, 'family_id' => TenantContext::familyId()]);

            if (!$row) { Response::error('Mitglied nicht gefunden.', 404); }

            $manualSum = (int) $db->prepare(
                'SELECT COALESCE(SUM(points), 0)' . "\n" .
                '  FROM member_points_ledger' . "\n" .
                ' WHERE family_member_id = :mid AND task_id IS NULL'
            )->fetchColumn(['mid' => $mid]);

            Response::json([
                'member_id'   => (int) $row['member_id'],
                'display_name' => $row['display_name'],
                'balance'     => (int) $row['balance'] + $manualSum,
            ]);
            return;
        }

        $rows = $db->prepare(
            'SELECT fm.id, fm.display_name,' . "\n" .
            '       COALESCE(SUM(mpl.points), 0) AS balance' . "\n" .
            '  FROM family_members fm' . "\n" .
            '  LEFT JOIN member_points_ledger mpl' . "\n" .
            '    ON mpl.family_member_id = fm.id AND mpl.task_id IS NOT NULL' . "\n" .
            ' WHERE fm.family_id = :family_id' . "\n" .
            ' GROUP BY fm.id, fm.display_name' . "\n" .
            ' ORDER BY fm.display_name ASC'
        )->execute(['family_id' => TenantContext::familyId()])->fetchAll();

        $result = array_map(static fn (array $r): array => [
            'member_id'   => (int) $r['id'],
            'display_name' => $r['display_name'],
            'balance'     => (int) $r['balance'],
        ], $rows);

        if (!TenantContext::isAdmin()) {
            foreach ($result as &$entry) {
                $manual = (int) $db->prepare(
                    'SELECT COALESCE(SUM(points), 0)' . "\n" .
                    '  FROM member_points_ledger' . "\n" .
                    ' WHERE family_member_id = :mid AND task_id IS NULL'
                )->fetchColumn(['mid' => $entry['member_id']]);
                $entry['balance'] += $manual;
            }
            unset($entry);
        }

        Response::json($result);
    }

    public function history(Request $request): void
    {
        $db = Database::connection();
        $memberId = (int) $request->params['memberId'];
        $this->assertOwnOrAdminMember($db, $memberId);

        $limit = min(max((int) ($request->query['limit'] ?? 50), 1), 200);

        $rows = $db->prepare(
            'SELECT mpl.id, mpl.task_id, mpl.points, mpl.reason, mpl.created_at,' . "\n" .
            '       mfm.display_name AS created_by_name,' . "\n" .
            '       t.title AS task_title' . "\n" .
            '  FROM member_points_ledger mpl' . "\n" .
            '  LEFT JOIN family_members mfm ON mfm.id = mpl.created_by_member_id' . "\n" .
            '  LEFT JOIN tasks t ON t.id = mpl.task_id' . "\n" .
            ' WHERE mpl.family_member_id = :member_id' . "\n" .
            ' ORDER BY mpl.created_at DESC LIMIT :limit'
        )->execute([
            'member_id' => $memberId,
            'limit'     => $limit,
        ])->fetchAll();

        $entries = array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'task_id'        => $r['task_id'] !== null ? (int) $r['task_id'] : null,
            'task_title'     => $r['task_title'],
            'points'         => (int) $r['points'],
            'reason'         => $r['reason'],
            'created_at'     => $r['created_at'],
            'created_by_name' => $r['created_by_name'],
        ], $rows);

        Response::json($entries);
    }

    public function adjust(Request $request): void
    {
        $db = Database::connection();
        $memberId = (int) $request->params['memberId'];
        TenantContext::requireAdmin();
        $this->assertOwnMember($db, $memberId);

        $points = (int) ($request->body['points'] ?? 0);
        if ($points === 0) { Response::error('points darf nicht 0 sein.', 422); }

        $reason = (string) ($request->body['reason'] ?? '');
        if (!in_array($reason, ['manual_adjustment', 'redemption'], true)) {
            Response::error('reason muss manual_adjustment oder redemption sein.', 422);
        }

        if ($reason === 'redemption') {
            $currentBalance = (int) $db->prepare(
                'SELECT COALESCE(SUM(points), 0)' . "\n" .
                '  FROM member_points_ledger' . "\n" .
                ' WHERE family_member_id = :member_id'
            )->fetchColumn(['member_id' => $memberId]);
            if ($currentBalance + $points < 0) {
                Response::error('Der aktuelle Punktestand reicht für diese Einlösung nicht aus.', 422);
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare(
            'INSERT INTO member_points_ledger' . "\n" .
            '   (family_member_id, task_id, points, reason, created_by_member_id, created_at)' . "\n" .
            ' VALUES (:family_member_id, NULL, :points, :reason, :created_by_member_id, :created_at)'
        )->execute([
            'family_member_id' => $memberId,
            'points'           => $points,
            'reason'           => $reason,
            'created_by_member_id' => TenantContext::familyMemberId(),
            'created_at'       => $now,
        ]);

        $newBalance = (int) $db->prepare(
            'SELECT COALESCE(SUM(points), 0)' . "\n" .
            '  FROM member_points_ledger' . "\n" .
            ' WHERE family_member_id = :member_id'
        )->fetchColumn(['member_id' => $memberId]);

        Response::json([
            'status'         => 'adjusted',
            'new_balance'    => $newBalance,
            'points_applied' => $points,
        ]);
    }

    /** Legt einen Ledger-Eintrag an (intern für automatische Buchungen). */
    public static function createLedgerEntry(\PDO $db, int $familyMemberId, int $taskId, int $points, string $reason): array
    {
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            'INSERT INTO member_points_ledger' . "\n" .
            '   (family_member_id, task_id, points, reason, created_by_member_id, created_at)' . "\n" .
            ' VALUES (:family_member_id, :task_id, :points, :reason, :created_by_member_id, :created_at)'
        )->execute([
            'family_member_id' => $familyMemberId,
            'task_id'          => $taskId > 0 ? $taskId : null,
            'points'           => $points,
            'reason'           => $reason,
            'created_by_member_id' => TenantContext::familyMemberId(),
            'created_at'       => $now,
        ]);

        $newBalance = (int) $db->prepare(
            'SELECT COALESCE(SUM(points), 0)' . "\n" .
            '  FROM member_points_ledger' . "\n" .
            ' WHERE family_member_id = :member_id'
        )->fetchColumn(['member_id' => $familyMemberId]);

        return ['id' => (int) $db->lastInsertId(), 'new_balance' => $newBalance];
    }

    private function assertOwnMember(\PDO $db, int $memberId): void
    {
        $stmt = $db->prepare(
            'SELECT id FROM family_members' . "\n" .
            ' WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) { Response::error('Mitglied gehört nicht zu dieser Familie.', 422); }
    }

    private function assertOwnOrAdminMember(\PDO $db, int $memberId): void
    {
        $currentMemberId = TenantContext::familyMemberId();
        if ($currentMemberId !== $memberId && !TenantContext::isAdmin()) {
            Response::error('Nur Sie selbst oder ein Admin dürfen darauf zugreifen.', 403);
        }
        $this->assertOwnMember($db, $memberId);
    }
}

    public function adjust(Request $request): void
    {
        $db = Database::connection();
        $memberId = (int) $request->params['memberId'];
        TenantContext::requireAdmin();
        $this->assertOwnMember($db, $memberId);

        $points = (int) ($request->body['points'] ?? 0);
        if ($points === 0) { Response::error('points darf nicht 0 sein.', 422); }

        $reason = (string) ($request->body['reason'] ?? '');
        if (!in_array($reason, ['manual_adjustment', 'redemption'], true)) {
            Response::error('reason muss manual_adjustment oder redemption sein.', 422);
        }

        if ($reason === 'redemption') {
            $currentBalance = (int) $db->prepare(
                'SELECT COALESCE(SUM(points), 0)' . "\n" .
                '  FROM member_points_ledger' . "\n" .
                ' WHERE family_member_id = :member_id'
            )->fetchColumn(['member_id' => $memberId]);
            if ($currentBalance + $points < 0) {
                Response::error('Der aktuelle Punktestand reicht für diese Einlösung nicht aus.', 422);
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare(
            'INSERT INTO member_points_ledger' . "\n" .
            '   (family_member_id, task_id, points, reason, created_by_member_id, created_at)' . "\n" .
            ' VALUES (:family_member_id, NULL, :points, :reason, :created_by_member_id, :created_at)'
        )->execute([
            'family_member_id' => $memberId,
            'points'           => $points,
            'reason'           => $reason,
            'created_by_member_id' => TenantContext::familyMemberId(),
            'created_at'       => $now,
        ]);

        $newBalance = (int) $db->prepare(
            'SELECT COALESCE(SUM(points), 0)' . "\n" .
            '  FROM member_points_ledger' . "\n" .
            ' WHERE family_member_id = :member_id'
        )->fetchColumn(['member_id' => $memberId]);

        Response::json([
            'status'         => 'adjusted',
            'new_balance'    => $newBalance,
            'points_applied' => $points,
        ]);
    }

    /** Legt einen Ledger-Eintrag an (intern für automatische Buchungen). */
    public static function createLedgerEntry(\PDO $db, int $familyMemberId, int $taskId, int $points, string $reason): array
    {
        $now = date('Y-m-d H:i:s');
        $db->prepare(
            'INSERT INTO member_points_ledger' . "\n" .
            '   (family_member_id, task_id, points, reason, created_by_member_id, created_at)' . "\n" .
            ' VALUES (:family_member_id, :task_id, :points, :reason, :created_by_member_id, :created_at)'
        )->execute([
            'family_member_id' => $familyMemberId,
            'task_id'          => $taskId > 0 ? $taskId : null,
            'points'           => $points,
            'reason'           => $reason,
            'created_by_member_id' => TenantContext::familyMemberId(),
            'created_at'       => $now,
        ]);

        $newBalance = (int) $db->prepare(
            'SELECT COALESCE(SUM(points), 0)' . "\n" .
            '  FROM member_points_ledger' . "\n" .
            ' WHERE family_member_id = :member_id'
        )->fetchColumn(['member_id' => $familyMemberId]);

        return ['id' => (int) $db->lastInsertId(), 'new_balance' => $newBalance];
    }

    private function assertOwnMember(\PDO $db, int $memberId): void
    {
        $stmt = $db->prepare(
            'SELECT id FROM family_members' . "\n" .
            ' WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) { Response::error('Mitglied gehört nicht zu dieser Familie.', 422); }
    }

    private function assertOwnOrAdminMember(\PDO $db, int $memberId): void
    {
        $currentMemberId = TenantContext::familyMemberId();
        if ($currentMemberId !== $memberId && !TenantContext::isAdmin()) {
            Response::error('Nur Sie selbst oder ein Admin dürfen darauf zugreifen.', 403);
        }
        $this->assertOwnMember($db, $memberId);
    }
}