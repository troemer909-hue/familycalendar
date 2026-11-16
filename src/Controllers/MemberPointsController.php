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

            Response::json($this->balanceFor($db, $mid));
            return;
        }

        $stmt = $db->prepare(
            'SELECT fm.id, fm.display_name,' . "\n" .
            '       COALESCE(SUM(mpl.points), 0) AS balance' . "\n" .
            '  FROM family_members fm' . "\n" .
            '  LEFT JOIN member_points_ledger mpl' . "\n" .
            '    ON mpl.family_member_id = fm.id' . "\n" .
            ' WHERE fm.family_id = :family_id' . "\n" .
            ' GROUP BY fm.id, fm.display_name' . "\n" .
            ' ORDER BY fm.display_name ASC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);
        $rows = $stmt->fetchAll();

        $result = array_map(static fn (array $r): array => [
            'member_id'    => (int) $r['id'],
            'display_name' => $r['display_name'],
            'balance'      => (int) $r['balance'],
        ], $rows);

        Response::json($result);
    }

    /** @return array{member_id:int, display_name:string, balance:int} */
    private function balanceFor(\PDO $db, int $memberId): array
    {
        $stmt = $db->prepare(
            'SELECT fm.id AS member_id, fm.display_name,' . "\n" .
            '       COALESCE(SUM(mpl.points), 0) AS balance' . "\n" .
            '  FROM family_members fm' . "\n" .
            '  LEFT JOIN member_points_ledger mpl' . "\n" .
            '    ON mpl.family_member_id = fm.id' . "\n" .
            ' WHERE fm.id = :id AND fm.family_id = :family_id' . "\n" .
            ' GROUP BY fm.id, fm.display_name'
        );
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Mitglied nicht gefunden.', 404);
        }

        return [
            'member_id'    => (int) $row['member_id'],
            'display_name' => $row['display_name'],
            'balance'      => (int) $row['balance'],
        ];
    }

    public function history(Request $request): void
    {
        $db = Database::connection();
        $memberId = (int) $request->params['memberId'];
        $this->assertOwnOrAdminMember($db, $memberId);

        $limit = min(max((int) ($request->query['limit'] ?? 50), 1), 200);

        $stmt = $db->prepare(
            'SELECT mpl.id, mpl.task_id, mpl.points, mpl.reason, mpl.created_at,' . "\n" .
            '       mfm.display_name AS created_by_name,' . "\n" .
            '       t.title AS task_title' . "\n" .
            '  FROM member_points_ledger mpl' . "\n" .
            '  LEFT JOIN family_members mfm ON mfm.id = mpl.created_by_member_id' . "\n" .
            '  LEFT JOIN tasks t ON t.id = mpl.task_id' . "\n" .
            ' WHERE mpl.family_member_id = :member_id' . "\n" .
            ' ORDER BY mpl.created_at DESC LIMIT ' . $limit
        );
        $stmt->execute(['member_id' => $memberId]);
        $rows = $stmt->fetchAll();

        $entries = array_map(static fn (array $r): array => [
            'id'              => (int) $r['id'],
            'task_id'         => $r['task_id'] !== null ? (int) $r['task_id'] : null,
            'task_title'      => $r['task_title'],
            'points'          => (int) $r['points'],
            'reason'          => $r['reason'],
            'created_at'      => $r['created_at'],
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
        if ($points === 0) {
            Response::error('points darf nicht 0 sein.', 422);
        }

        $reason = (string) ($request->body['reason'] ?? '');
        if (!in_array($reason, ['manual_adjustment', 'redemption'], true)) {
            Response::error('reason muss manual_adjustment oder redemption sein.', 422);
        }

        if ($reason === 'redemption') {
            $currentBalance = $this->currentBalance($db, $memberId);
            if ($currentBalance + $points < 0) {
                Response::error('Der aktuelle Punktestand reicht für diese Einlösung nicht aus.', 422);
            }
        }

        $result = self::createLedgerEntry($db, $memberId, null, $points, $reason);

        Response::json([
            'status'         => 'adjusted',
            'new_balance'    => $result['new_balance'],
            'points_applied' => $points,
        ]);
    }

    /**
     * Legt einen Ledger-Eintrag an und liefert den neuen Saldo zurück -
     * sowohl für automatische Buchungen (Aufgaben-Abschluss/Storno, siehe
     * TaskListController::updateTask()) als auch für manuelle Admin-
     * Anpassungen (siehe adjust() oben).
     *
     * @return array{id:int, new_balance:int}
     */
    public static function createLedgerEntry(\PDO $db, int $familyMemberId, ?int $taskId, int $points, string $reason): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO member_points_ledger' . "\n" .
            '   (family_member_id, task_id, points, reason, created_by_member_id, created_at)' . "\n" .
            ' VALUES (:family_member_id, :task_id, :points, :reason, :created_by_member_id, :created_at)'
        );
        $stmt->execute([
            'family_member_id'     => $familyMemberId,
            'task_id'              => $taskId,
            'points'               => $points,
            'reason'               => $reason,
            'created_by_member_id' => TenantContext::familyMemberId(),
            'created_at'           => $now,
        ]);

        $id = (int) $db->lastInsertId();
        $newBalance = self::currentBalanceStatic($db, $familyMemberId);

        return ['id' => $id, 'new_balance' => $newBalance];
    }

    private function currentBalance(\PDO $db, int $memberId): int
    {
        return self::currentBalanceStatic($db, $memberId);
    }

    private static function currentBalanceStatic(\PDO $db, int $memberId): int
    {
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(points), 0)' . "\n" .
            '  FROM member_points_ledger' . "\n" .
            ' WHERE family_member_id = :member_id'
        );
        $stmt->execute(['member_id' => $memberId]);

        return (int) $stmt->fetchColumn();
    }

    private function assertOwnMember(\PDO $db, int $memberId): void
    {
        $stmt = $db->prepare(
            'SELECT id FROM family_members' . "\n" .
            ' WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) {
            Response::error('Mitglied gehört nicht zu dieser Familie.', 422);
        }
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
