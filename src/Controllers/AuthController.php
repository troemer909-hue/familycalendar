<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
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
}
