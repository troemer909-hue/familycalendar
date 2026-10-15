<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;
use Familienkalender\Core\Token;

final class MemberController
{
    /**
     * GET /api/members
     * Alle Personen der eigenen Familie – Profile ohne Login UND Vollmitglieder.
     */
    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, display_name, display_color, role, member_type, birth_year
             FROM family_members
             WHERE family_id = :family_id
             ORDER BY member_type DESC, display_name ASC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        Response::json($stmt->fetchAll());
    }

    /**
     * POST /api/members
     * Body: { display_name, display_color?, birth_year? }
     * Legt ein Profil OHNE Login an (z.B. für ein Kind). Nur Admin.
     */
    public function createProfile(Request $request): void
    {
        TenantContext::requireAdmin();

        $name = trim((string) $request->input('display_name', ''));
        if ($name === '') {
            Response::error('display_name ist erforderlich.', 422);
        }

        $db = Database::connection();

        $this->assertMemberLimitNotReached($db);

        $stmt = $db->prepare(
            'INSERT INTO family_members (family_id, user_id, display_name, display_color, role, member_type, birth_year)
             VALUES (:family_id, NULL, :name, :color, "member", "profile", :birth_year)'
        );
        $stmt->execute([
            'family_id' => TenantContext::familyId(),
            'name' => $name,
            'color' => (string) $request->input('display_color', '#94a3b8'),
            'birth_year' => $request->input('birth_year'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    /**
     * POST /api/members/{id}/upgrade
     * Body: { email, password }
     * Stuft ein bestehendes Profil ohne Login zu einem Vollmitglied hoch,
     * ohne die bisherige family_member-ID (und damit Termine/Historie) zu verlieren.
     */
    public function upgrade(Request $request): void
    {
        TenantContext::requireAdmin();

        $memberId = (int) $request->params['id'];
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if ($email === '' || strlen($password) < 8) {
            Response::error('email und ein Passwort (min. 8 Zeichen) sind erforderlich.', 422);
        }

        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT id, member_type FROM family_members WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        $member = $stmt->fetch();

        if (!$member) {
            Response::error('Person nicht gefunden.', 404);
        }
        if ($member['member_type'] === 'login') {
            Response::error('Diese Person hat bereits ein Login.', 409);
        }

        $emailCheck = $db->prepare('SELECT id FROM users WHERE email = :email');
        $emailCheck->execute(['email' => $email]);
        if ($emailCheck->fetch()) {
            Response::error('Diese E-Mail-Adresse wird bereits verwendet.', 409);
        }

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash)')
                ->execute([
                    'email' => $email,
                    'hash' => password_hash($password, PASSWORD_BCRYPT),
                ]);
            $userId = (int) $db->lastInsertId();

            $db->prepare(
                'UPDATE family_members SET user_id = :user_id, member_type = "login" WHERE id = :id'
            )->execute(['user_id' => $userId, 'id' => $memberId]);

            $db->prepare('INSERT INTO notification_preferences (user_id) VALUES (:user_id)')
                ->execute(['user_id' => $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Upgrade fehlgeschlagen: ' . $e->getMessage());
            Response::error('Upgrade fehlgeschlagen.', 500);
        }

        Response::json(['status' => 'upgraded', 'member_id' => $memberId], 200);
    }

    /**
     * DELETE /api/members/{id}/login
     * Entzieht ein Login (Downgrade zu reinem Profil). Person, Termine und
     * Historie bleiben erhalten – nur der Zugang wird entfernt.
     */
    public function downgrade(Request $request): void
    {
        TenantContext::requireAdmin();

        $memberId = (int) $request->params['id'];
        $db = Database::connection();

        $stmt = $db->prepare(
            'SELECT id, user_id, role FROM family_members WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        $member = $stmt->fetch();

        if (!$member) {
            Response::error('Person nicht gefunden.', 404);
        }
        if ($member['role'] === 'admin') {
            Response::error('Dem Familienadmin kann das Login nicht entzogen werden.', 409);
        }
        if (!$member['user_id']) {
            Response::error('Diese Person hat ohnehin kein Login.', 409);
        }

        $db->prepare(
            'UPDATE family_members SET user_id = NULL, member_type = "profile" WHERE id = :id'
        )->execute(['id' => $memberId]);

        // Bewusst: der users-Datensatz selbst bleibt bestehen (Login ist nur
        // "entkoppelt", kein Datenverlust) – Bereinigung/Löschung wäre ein
        // separater, expliziter Vorgang (z.B. im Rahmen der DSGVO-Löschung).

        Response::json(['status' => 'downgraded', 'member_id' => $memberId]);
    }

    /**
     * POST /api/members/{id}/transfer-admin
     * Übergibt die Familienadmin-Rolle an ein anderes Vollmitglied (muss
     * bereits ein eigenes Login haben) und stuft sich selbst zu "member"
     * herab. Es gibt bewusst immer GENAU EINEN Admin pro Familie - darauf
     * verlässt sich z.B. downgrade() ("Dem Familienadmin kann das Login
     * nicht entzogen werden").
     */
    public function transferAdmin(Request $request): void
    {
        TenantContext::requireAdmin();

        $targetId = (int) $request->params['id'];
        $currentMemberId = TenantContext::familyMemberId();

        if ($targetId === $currentMemberId) {
            Response::error('Du bist bereits Admin.', 409);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, member_type FROM family_members WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute(['id' => $targetId, 'family_id' => TenantContext::familyId()]);
        $target = $stmt->fetch();

        if (!$target) {
            Response::error('Person nicht gefunden.', 404);
        }
        if ($target['member_type'] !== 'login') {
            Response::error('Diese Person braucht zuerst ein eigenes Login, bevor sie Admin werden kann.', 409);
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE family_members SET role = "member" WHERE id = :id')
                ->execute(['id' => $currentMemberId]);
            $db->prepare('UPDATE family_members SET role = "admin" WHERE id = :id')
                ->execute(['id' => $targetId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Admin-Übertragung fehlgeschlagen: ' . $e->getMessage());
            Response::error('Admin-Übertragung fehlgeschlagen.', 500);
        }

        // Die eigene Session bleibt gültig (nur noch als "member") - die
        // Rolle wird bei jedem Request frisch aus der DB gelesen (siehe
        // AuthMiddleware), kein Token-Refresh/Logout nötig.
        Response::json(['status' => 'transferred', 'new_admin_member_id' => $targetId]);
    }

    private function assertMemberLimitNotReached(\PDO $db): void
    {
        $stmt = $db->prepare(
            'SELECT p.max_members, (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id) AS current_count
             FROM families f
             JOIN plans p ON p.id = f.plan_id
             WHERE f.id = :family_id'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);
        $row = $stmt->fetch();

        if ($row && (int) $row['current_count'] >= (int) $row['max_members']) {
            Response::error('Mitgliederlimit des aktuellen Plans erreicht.', 403);
        }
    }
}
