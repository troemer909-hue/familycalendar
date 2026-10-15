<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\Mailer;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\Token;
use PDO;

final class AuthController
{
    /**
     * POST /api/auth/register
     * Body: { family_name, admin_name, email, password }
     * Legt eine neue Familie (Tenant) + den ersten Vollmitglied-Admin an.
     */
    public function register(Request $request): void
    {
        $familyName = trim((string) $request->input('family_name', ''));
        $adminName = trim((string) $request->input('admin_name', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if ($familyName === '' || $adminName === '' || $email === '' || strlen($password) < 8) {
            Response::error('family_name, admin_name, email und ein Passwort (min. 8 Zeichen) sind erforderlich.', 422);
        }

        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            Response::error('Diese E-Mail-Adresse wird bereits verwendet.', 409);
        }

        $db->beginTransaction();
        try {
            // Standard-Plan als Default für neue Familien (Abrechnung ist bewusst kein MVP-Bestandteil,
            // siehe Konzept Abschnitt 9 – Familien starten manuell im Trial auf "standard").
            $planStmt = $db->prepare("SELECT id FROM plans WHERE code = 'standard' LIMIT 1");
            $planStmt->execute();
            $planId = $planStmt->fetchColumn();

            $db->prepare('INSERT INTO families (name, plan_id, status) VALUES (:name, :plan_id, :status)')
                ->execute([
                    'name' => $familyName,
                    'plan_id' => $planId ?: null,
                    'status' => 'trial',
                ]);
            $familyId = (int) $db->lastInsertId();

            $db->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash)')
                ->execute([
                    'email' => $email,
                    'hash' => password_hash($password, PASSWORD_BCRYPT),
                ]);
            $userId = (int) $db->lastInsertId();

            $db->prepare(
                'INSERT INTO family_members (family_id, user_id, display_name, display_color, role, member_type)
                 VALUES (:family_id, :user_id, :name, :color, "admin", "login")'
            )->execute([
                'family_id' => $familyId,
                'user_id' => $userId,
                'name' => $adminName,
                'color' => '#3b82f6',
            ]);

            $db->prepare('INSERT INTO notification_preferences (user_id) VALUES (:user_id)')
                ->execute(['user_id' => $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Registrierung fehlgeschlagen: ' . $e->getMessage());
            Response::error('Registrierung fehlgeschlagen.', 500);
        }

        $token = Token::issue([
            'user_id' => $userId,
            'exp' => time() + 60 * 60 * 24 * 30, // 30 Tage
        ]);

        Response::json([
            'token' => $token,
            'family_id' => $familyId,
        ], 201);
    }

    /**
     * POST /api/auth/login
     * Body: { email, password }
     */
    public function login(Request $request): void
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Bewusst gleiche Fehlermeldung für "unbekannt" und "falsches Passwort"
            // (kein User-Enumeration-Leak).
            Response::error('E-Mail oder Passwort ist falsch.', 401);
        }

        $token = Token::issue([
            'user_id' => (int) $user['id'],
            'exp' => time() + 60 * 60 * 24 * 30,
        ]);

        Response::json(['token' => $token]);
    }

    /**
     * POST /api/auth/password-reset/request
     * Body: { email }
     * Antwortet IMMER mit derselben generischen Erfolgsmeldung, unabhängig
     * davon, ob die E-Mail-Adresse existiert (kein User-Enumeration-Leak,
     * analog zu login()). Der eigentliche Mail-Versand passiert nur, wenn
     * ein passender Nutzer gefunden wurde.
     */
    public function requestPasswordReset(Request $request): void
    {
        $email = trim((string) $request->input('email', ''));
        if ($email === '') {
            Response::error('E-Mail ist erforderlich.', 422);
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $userId = (int) $user['id'];
            $token = bin2hex(random_bytes(32));

            // Noch nicht eingelöste alte Tokens dieses Nutzers verfallen
            // lassen - immer nur der zuletzt angeforderte Link soll gelten.
            $db->prepare('DELETE FROM password_resets WHERE user_id = :user_id AND used_at IS NULL')
                ->execute(['user_id' => $userId]);

            $db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL 60 MINUTE))'
            )->execute([
                'user_id' => $userId,
                'hash' => hash('sha256', $token),
            ]);

            $this->sendResetMail($email, $token);
        }

        Response::json([
            'status' => 'ok',
            'message' => 'Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde ein Link zum Zurücksetzen des Passworts versendet.',
        ]);
    }

    /**
     * POST /api/auth/password-reset/confirm
     * Body: { token, password }
     */
    public function confirmPasswordReset(Request $request): void
    {
        $token = trim((string) $request->input('token', ''));
        $password = (string) $request->input('password', '');

        if ($token === '' || strlen($password) < 8) {
            Response::error('Code und ein neues Passwort (min. 8 Zeichen) sind erforderlich.', 422);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $reset = $stmt->fetch();

        if (!$reset) {
            Response::error('Dieser Link ist ungültig oder abgelaufen. Bitte neu anfordern.', 422);
        }

        $userId = (int) $reset['user_id'];

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => password_hash($password, PASSWORD_BCRYPT), 'id' => $userId]);

            // ALLE offenen Reset-Tokens dieses Nutzers invalidieren, nicht nur
            // das benutzte - verhindert, dass ein zweiter, noch gültiger Link
            // aus derselben Anfrage-Historie weiter funktioniert.
            $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
                ->execute(['user_id' => $userId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Passwort-Reset fehlgeschlagen: ' . $e->getMessage());
            Response::error('Passwort konnte nicht geändert werden.', 500);
        }

        Response::json(['status' => 'ok', 'message' => 'Passwort wurde geändert. Du kannst dich jetzt anmelden.']);
    }

    /**
     * Baut den Reset-Link NUR aus der server-seitig konfigurierten
     * APP_PUBLIC_URL (.env) - bewusst NICHT aus dem Host-Header des
     * Requests, der sich fälschen lässt (klassische "Password Reset
     * Poisoning"-Lücke). Ist APP_PUBLIC_URL nicht gesetzt, bekommt der
     * Nutzer stattdessen den rohen Code zum manuellen Eintragen.
     */
    private function sendResetMail(string $email, string $token): void
    {
        $publicUrl = rtrim((string) Env::get('APP_PUBLIC_URL', ''), '/');

        $body = $publicUrl !== ''
            ? "Klicke auf folgenden Link, um dein Passwort zurückzusetzen (60 Minuten gültig):\n\n"
                . "{$publicUrl}/reset_password.php?token={$token}\n\n"
                . "Falls du das nicht angefordert hast, kannst du diese E-Mail ignorieren."
            : "Dein Code zum Zurücksetzen des Passworts (60 Minuten gültig):\n\n{$token}\n\n"
                . "Trage ihn auf der Seite \"Passwort zurücksetzen\" ein. "
                . "Falls du das nicht angefordert hast, kannst du diese E-Mail ignorieren.";

        if (!Mailer::send($email, 'Passwort zurücksetzen', $body)) {
            error_log("Passwort-Reset-Mail an {$email} konnte nicht versendet werden.");
        }
    }
}
