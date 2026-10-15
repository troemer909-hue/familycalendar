<?php

declare(strict_types=1);

/**
 * Cron-Job für laufende ICS-Abos (calendar_sources.type = 'stream_ics').
 * Läuft NICHT über eine HTTP-Session/TenantContext, sondern iteriert
 * direkt über alle Familien - auf Shared Hosting i.d.R. per Cronjob
 * eingerichtet, z.B. (cPanel "Cron Jobs" oder äquivalent), alle 15 Minuten:
 *
 *   php /pfad/zum/projekt/bin/sync-calendar-sources.php
 *
 * Das Intervall hier steuert nur, wie oft geprüft wird, ob eine Quelle
 * fällig ist - tatsächlich neu geladen wird jede Quelle gemäß ihrem
 * eigenen sync_interval_minutes (siehe calendar_sources.php im Client).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Familienkalender\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Familienkalender\Core\CalendarSync;
use Familienkalender\Core\Database;
use Familienkalender\Core\Env;

Env::load(__DIR__ . '/../.env');

$db = Database::connection();
$stmt = $db->query(
    "SELECT * FROM calendar_sources
     WHERE type = 'stream_ics'
       AND status != 'paused'
       AND (last_synced_at IS NULL
            OR last_synced_at <= DATE_SUB(NOW(), INTERVAL sync_interval_minutes MINUTE))"
);

$dueCount = 0;
foreach ($stmt->fetchAll() as $source) {
    $dueCount++;
    try {
        $count = CalendarSync::syncStreamSource($source);
        $db->prepare('UPDATE calendar_sources SET status = "active", last_synced_at = NOW() WHERE id = :id')
            ->execute(['id' => $source['id']]);
        echo "[OK] Quelle #{$source['id']} ({$source['name']}): {$count} Termine synchronisiert.\n";
    } catch (\Throwable $e) {
        $db->prepare('UPDATE calendar_sources SET status = "error" WHERE id = :id')
            ->execute(['id' => $source['id']]);
        error_log("Kalender-Sync fehlgeschlagen (source {$source['id']}): " . $e->getMessage());
        echo "[FEHLER] Quelle #{$source['id']} ({$source['name']}): {$e->getMessage()}\n";
    }
}

if ($dueCount === 0) {
    echo "Keine fälligen Abos.\n";
}
