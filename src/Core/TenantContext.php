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
    private static bool $isPlatformAdmin = false;

    public static function set(int $userId, int $familyId, int $familyMemberId, string $role, bool $isPlatformAdmin = false): void
    {
        self::$userId = $userId;
        self::$familyId = $familyId;
        self::$familyMemberId = $familyMemberId;
        self::$role = $role;
        self::$isPlatformAdmin = $isPlatformAdmin;
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

    /**
     * Plattform-weite Administration (Pläne/Billing über alle Familien
     * hinweg) ist bewusst von der familieninternen Admin-Rolle getrennt:
     * ein Familienadmin verwaltet nur seine eigene Familie, ein
     * Plattform-Admin (users.is_platform_admin) kann Pläne und den
     * Abrechnungsstatus JEDER Familie ändern. Es gibt aktuell keine
     * Selbstbedienungs-Funktion, um sich selbst zum Plattform-Admin zu
     * machen - das erste Konto wird einmalig manuell per SQL gesetzt
     * (siehe README, Abschnitt "Plattform-Verwaltung").
     */
    public static function isPlatformAdmin(): bool
    {
        return self::$isPlatformAdmin;
    }

    public static function requirePlatformAdmin(): void
    {
        if (!self::isPlatformAdmin()) {
            Response::error('Nur für Plattform-Administratoren.', 403);
        }
    }
}
