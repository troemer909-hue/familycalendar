<?php

declare(strict_types=1);

/**
 * Cron-Job für die zufällige Aufgaben-Zuteilung (siehe
 * docs/feature-ideas/aufgaben-zufallsverteilung.md). Läuft NICHT über
 * eine HTTP-Session/TenantContext, sondern iteriert direkt über alle
 * Familien - auf Shared Hosting i.d.R. per Cronjob eingerichtet
 * (cPanel "Cron Jobs" o.ä.), einmal täglich:
 *
 *   php /pfad/zum/projekt/bin/draw-task-assignments.php
 *
 * Wie oft hier geprüft wird, bestimmt nur, wie zeitnah eine fällige
 * Ziehung nach Erreichen des Rhythmus (täglich/wöchentlich/monatlich)
 * tatsächlich passiert - ein einmal täglicher Lauf reicht für alle drei
 * Rhythmen, siehe TaskAssignmentRunner::runDuePools().
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

use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\TaskAssignmentRunner;

Env::load(__DIR__ . '/../.env');

$db = Database::connection();
$results = TaskAssignmentRunner::runDuePools($db);

if (empty($results)) {
    echo "Keine fälligen Ziehungen.\n";
}

foreach ($results as $r) {
    echo "[OK] Pool #{$r['pool_id']} (Liste {$r['task_list_id']}): {$r['drawn']} verlost, {$r['unassigned']} unzugeteilt.\n";
}
