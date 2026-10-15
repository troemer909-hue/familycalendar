<?php

declare(strict_types=1);

namespace Familienkalender\Middleware;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;
use Familienkalender\Core\Token;

/**
 * Wird vor jeder geschützten Route ausgeführt. Löst aus dem Bearer-Token
 * den family_members-Datensatz auf und setzt damit automatisch die
 * family_id für alle nachfolgenden Datenbankzugriffe (Mandantentrennung).
 */
final class AuthMiddleware
{
    public function __invoke(Request $request): void
    {
        $token = $request->bearerToken;
        if (!$token) {
            Response::error('Nicht angemeldet (kein Token übergeben).', 401);
        }

        $claims = Token::verify($token);
        if (!$claims || !isset($claims['user_id'])) {
            Response::error('Token ungültig oder abgelaufen.', 401);
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT fm.id, fm.family_id, fm.role, u.is_platform_admin
             FROM family_members fm
             JOIN users u ON u.id = fm.user_id
             WHERE fm.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $claims['user_id']]);
        $member = $stmt->fetch();

        if (!$member) {
            Response::error('Kein Familienmitglied zu diesem Konto gefunden.', 403);
        }

        TenantContext::set(
            userId: (int) $claims['user_id'],
            familyId: (int) $member['family_id'],
            familyMemberId: (int) $member['id'],
            role: $member['role'],
            isPlatformAdmin: (bool) $member['is_platform_admin'],
        );
    }
}
