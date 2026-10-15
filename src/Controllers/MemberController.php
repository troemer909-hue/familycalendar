<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\Mailer;
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
     * POST /api/members/{id}/invite
     * Body: { email }
     * Lädt eine Person per E-Mail ein, ihr bestehendes Profil (ohne Login)
     * zu einem eigenen Vollmitglied-Login hochzustufen - der Admin gibt
     * bewusst NUR die E-Mail-Adresse an, das Passwort wählt die eingeladene
     * Person selbst über den Link (siehe AuthController::acceptInvite()).
     * Kein Passwort-Teilen außerhalb der App mehr nötig.
     */
    public function invite(Request $request): void
    {
        TenantContext::requireAdmin();

        $memberId = (int) $request->params['id'];
        $email = trim((string) $request->input('email', ''));

        if ($email === '') {
            Response::error('email ist erforderlich.', 422);
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

        // Noch nicht eingelöste alte Einladungen für diese Person verfallen
        // lassen - immer nur die zuletzt versendete Einladung soll gelten.
        $db->prepare('DELETE FROM member_invites WHERE family_member_id = :id AND used_at IS NULL')
            ->execute(['id' => $memberId]);

        $token = bin2hex(random_bytes(32));
        $db->prepare(
            'INSERT INTO member_invites (family_member_id, email, token_hash, expires_at)
             VALUES (:id, :email, :hash, DATE_ADD(NOW(), INTERVAL 7 DAY))'
        )->execute([
            'id' => $memberId,
            'email' => $email,
            'hash' => hash('sha256', $token),
        ]);

        $this->sendInviteMail($email, $token);

        Response::json(['status' => 'invited', 'member_id' => $memberId]);
    }

    /**
     * Baut den Einladungs-Link NUR aus der server-seitig konfigurierten
     * APP_PUBLIC_URL (.env) - bewusst NICHT aus dem Host-Header des
     * Requests (siehe AuthController::sendResetMail() für denselben Grund).
     * Ist APP_PUBLIC_URL nicht gesetzt, bekommt die eingeladene Person
     * stattdessen den rohen Code zum manuellen Eintragen.
     */
    private function sendInviteMail(string $email, string $token): void
    {
        $publicUrl = rtrim((string) Env::get('APP_PUBLIC_URL', ''), '/');

        $body = $publicUrl !== ''
            ? "Du wurdest zum Familienkalender eingeladen. Klicke auf folgenden Link, "
                . "um dein eigenes Passwort festzulegen (7 Tage gültig):\n\n"
                . "{$publicUrl}/accept_invite.php?token={$token}\n\n"
                . "Falls du das nicht erwartet hast, kannst du diese E-Mail ignorieren."
            : "Du wurdest zum Familienkalender eingeladen. Dein Code, um dein eigenes "
                . "Passwort festzulegen (7 Tage gültig):\n\n{$token}\n\n"
                . "Trage ihn auf der Seite \"Einladung annehmen\" ein. "
                . "Falls du das nicht erwartet hast, kannst du diese E-Mail ignorieren.";

        if (!Mailer::send($email, 'Einladung zum Familienkalender', $body)) {
            error_log("Einladungs-Mail an {$email} konnte nicht versendet werden.");
        }
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
