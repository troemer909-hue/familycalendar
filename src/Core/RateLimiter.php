<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Rate-Limiting für fehlgeschlagene Login-Versuche (AuthController::login()).
 * Bewusst datenbankbasiert statt z.B. Redis/APCu – passend zur restlichen
 * Codebasis (kein Composer, kein zusätzlicher Dienst nötig, läuft auf
 * jedem Shared-Hosting-Paket mit MySQL).
 *
 * Zwei unabhängige Grenzen:
 * - pro E-Mail-Adresse: verhindert Brute-Force gegen EIN Konto, egal von
 *   wie vielen IPs aus.
 * - pro IP-Adresse: verhindert schnelles Durchprobieren VIELER E-Mail-
 *   Adressen von einer Quelle aus (Enumeration/Credential-Spraying).
 *
 * Ein Zeitfenster genügt (kein fester "Lockout" mit eigenem Ablaufdatum):
 * sobald die ältesten Versuche aus dem Fenster herausfallen, sinkt die
 * Zählung automatisch wieder unter die Grenze.
 */
final class RateLimiter
{
    private const WINDOW_MINUTES = 15;
    private const MAX_ATTEMPTS_PER_EMAIL = 5;
    private const MAX_ATTEMPTS_PER_IP = 20;

    /** Client-IP aus REMOTE_ADDR - bewusst NICHT aus X-Forwarded-For, das
     *  sich ohne vorgeschalteten, vertrauenswürdigen Reverse-Proxy beliebig
     *  fälschen lässt und die IP-Grenze sonst wirkungslos machen würde. */
    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public static function tooManyLoginAttempts(string $email, string $ip): bool
    {
        $db = Database::connection();

        $emailCount = self::countSince($db, 'email', $email);
        if ($emailCount >= self::MAX_ATTEMPTS_PER_EMAIL) {
            return true;
        }

        $ipCount = self::countSince($db, 'ip_address', $ip);
        return $ipCount >= self::MAX_ATTEMPTS_PER_IP;
    }

    public static function recordFailedLogin(string $email, string $ip): void
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)')
            ->execute(['email' => $email, 'ip' => $ip]);

        // Opportunistische Aufräumung (kein eigener Cron nötig) - alles
        // deutlich außerhalb jedes möglichen Zeitfensters wird verworfen,
        // damit die Tabelle nicht unbegrenzt wächst.
        $db->exec('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }

    /** Bei erfolgreichem Login: eigene Fehlversuche zurücksetzen. */
    public static function clearFailedLogins(string $email): void
    {
        Database::connection()
            ->prepare('DELETE FROM login_attempts WHERE email = :email')
            ->execute(['email' => $email]);
    }

    private static function countSince(\PDO $db, string $column, string $value): int
    {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE {$column} = :value AND created_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)"
        );
        // Named placeholder für INTERVAL erfordert einen Integer-Bind -
        // PDO::ATTR_EMULATE_PREPARES ist false (siehe Database.php), daher
        // hier bewusst als Platzhalter statt String-Interpolation gebunden.
        $stmt->bindValue(':value', $value, \PDO::PARAM_STR);
        $stmt->bindValue(':minutes', self::WINDOW_MINUTES, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
