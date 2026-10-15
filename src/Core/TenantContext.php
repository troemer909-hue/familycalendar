<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Zentraler Ort für den "wer bin ich, zu welcher Familie gehöre ich"-Kontext
 * nach erfolgreicher Authentifizierung. Jede Datenzugriffsfunktion, die
 * Termine/Mitglieder liest oder schreibt, MUSS familyId() verwenden, um
 * Mandantentrennung zu garantieren (siehe Konzept Abschnitt 4/13).
 */
final class TenantContext
{
    private static ?int $userId = null;
    private static ?int $familyId = null;
    private static ?int $familyMemberId = null;
    private static ?string $role = null; // 'admin' | 'member'

    public static function set(int $userId, int $familyId, int $familyMemberId, string $role): void
    {
        self::$userId = $userId;
        self::$familyId = $familyId;
        self::$familyMemberId = $familyMemberId;
        self::$role = $role;
    }

    public static function userId(): int
    {
        return self::$userId ?? throw new \RuntimeException('Kein authentifizierter Benutzer im Kontext.');
    }

    public static function familyId(): int
    {
        return self::$familyId ?? throw new \RuntimeException('Kein Familienkontext gesetzt.');
    }

    public static function familyMemberId(): int
    {
        return self::$familyMemberId ?? throw new \RuntimeException('Kein Mitgliedskontext gesetzt.');
    }

    public static function isAdmin(): bool
    {
        return self::$role === 'admin';
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            Response::error('Nur der Familienadmin darf das.', 403);
        }
    }
}
