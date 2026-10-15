<?php

declare(strict_types=1);

/**
 * Cron-Job für den Versand fälliger Erinnerungen (reminders.sent = 0).
 * Läuft NICHT über eine HTTP-Session, sondern direkt gegen die DB - auf
 * Shared Hosting i.d.R. per Cronjob eingerichtet, alle paar Minuten:
 *
 *   php /pfad/zum/projekt/bin/send-reminders.php
 *
 * Eine Erinnerung ist "fällig", wenn (Terminbeginn - Vorlaufzeit) <= jetzt
 * UND der Termin selbst noch nicht begonnen hat (kein Nachsenden für
 * bereits laufende/vergangene Termine, falls der Cron mal ausgesetzt hat).
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
use Familienkalender\Core\Mailer;
use Familienkalender\Core\PushSender;

// Composer-Autoloader NUR für Push (siehe PushSender::isConfigured()) -
// fehlt vendor/, laufen E-Mail-Erinnerungen unverändert weiter.
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

Env::load(__DIR__ . '/../.env');

$db = Database::connection();
$stmt = $db->query(
    "SELECT r.id AS reminder_id, r.user_id, u.email, e.id AS event_id, e.title, e.location, e.starts_at, e.all_day
     FROM reminders r
     JOIN events e ON e.id = r.event_id
     JOIN users u ON u.id = r.user_id
     WHERE r.sent = 0
       AND e.starts_at > NOW()
       AND DATE_SUB(e.starts_at, INTERVAL r.remind_before_minutes MINUTE) <= NOW()"
);

$dueCount = 0;
foreach ($stmt->fetchAll() as $row) {
    $dueCount++;

    $when = $row['all_day']
        ? (new DateTimeImmutable($row['starts_at']))->format('d.m.Y') . ' (ganztägig)'
        : (new DateTimeImmutable($row['starts_at']))->format('d.m.Y \u\m H:i \U\h\r');

    $bodyLines = ["Erinnerung: {$row['title']}", '', "Wann: {$when}"];
    if (!empty($row['location'])) {
        $bodyLines[] = "Ort: {$row['location']}";
    }
    $body = implode("\n", $bodyLines);

    $mailSent = Mailer::send($row['email'], 'Erinnerung: ' . $row['title'], $body);

    $pushAttempts = 0;
    try {
        $pushAttempts = PushSender::sendToUser(
            (int) $row['user_id'],
            'Erinnerung: ' . $row['title'],
            $when . (!empty($row['location']) ? ' · ' . $row['location'] : ''),
            '/event_form.php?id=' . $row['event_id']
        );
    } catch (\Throwable $e) {
        error_log("Push-Erinnerung fehlgeschlagen (reminder #{$row['reminder_id']}): " . $e->getMessage());
    }

    // "sent" gilt als erledigt, sobald WENIGSTENS ein Kanal (Mail oder
    // Push) tatsächlich versucht wurde - Einzelfehler pro Gerät sind
    // bereits in PushSender geloggt, kein Grund für endlose Wiederholung.
    if ($mailSent || $pushAttempts > 0) {
        $db->prepare('UPDATE reminders SET sent = 1 WHERE id = :id')->execute(['id' => $row['reminder_id']]);
        echo "[OK] Erinnerung #{$row['reminder_id']}: Mail=" . ($mailSent ? 'ja' : 'nein')
            . ", Push-Geräte=" . $pushAttempts . "\n";
    } else {
        error_log("Erinnerung #{$row['reminder_id']} konnte über keinen Kanal versendet werden (an {$row['email']}).");
        echo "[FEHLER] Erinnerung #{$row['reminder_id']} konnte nicht versendet werden.\n";
    }
}

if ($dueCount === 0) {
    echo "Keine fälligen Erinnerungen.\n";
}
