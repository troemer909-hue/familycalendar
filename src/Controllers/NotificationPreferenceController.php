<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Zentrale Benachrichtigungseinstellungen je Login-Nutzer. Die Zeile wird
 * normalerweise schon bei Registrierung/Upgrade angelegt (siehe
 * AuthController::register() / MemberController::upgrade()) - die
 * defensive INSERT-Fallbacks hier fangen nur ältere/manuell angelegte
 * Nutzer ohne Zeile ab.
 *
 * own_events/family_events wirken als globale Schalter ZUSÄTZLICH zu den
 * einzelnen Erinnerungen, die ein Nutzer pro Termin anlegt: ist z.B.
 * family_events deaktiviert, werden fällige Erinnerungen zu
 * Familienterminen (events.scope = 'family') nicht zugestellt, auch wenn
 * eine Erinnerung dafür existiert - sie bleibt aber gespeichert und wird
 * zugestellt, sobald der Schalter wieder aktiviert wird (siehe
 * bin/send-reminders.php). channel_mail/channel_push schalten die beiden
 * Zustellkanäle unabhängig davon komplett ab bzw. an.
 */
final class NotificationPreferenceController
{
    private const COLUMNS = 'own_events, family_events, channel_mail, channel_push, reminder_default_minutes';

    /**
     * GET /api/notification-preferences
     */
    public function show(Request $request): void
    {
        Response::json($this->loadOrCreate(TenantContext::userId()));
    }

    /**
     * PUT /api/notification-preferences
     * Body: { own_events, family_events, channel_mail, channel_push, reminder_default_minutes }
     */
    public function update(Request $request): void
    {
        $minutes = (int) $request->input('reminder_default_minutes', 30);
        if ($minutes < 1 || $minutes > 10080) {
            Response::error('reminder_default_minutes muss zwischen 1 und 10080 (7 Tage) liegen.', 422);
        }

        $userId = TenantContext::userId();
        $this->loadOrCreate($userId); // stellt sicher, dass die Zeile existiert

        $db = Database::connection();
        $db->prepare(
            'UPDATE notification_preferences
             SET own_events = :own_events, family_events = :family_events,
                 channel_mail = :channel_mail, channel_push = :channel_push,
                 reminder_default_minutes = :minutes
             WHERE user_id = :user_id'
        )->execute([
            'own_events' => $this->boolInput($request, 'own_events') ? 1 : 0,
            'family_events' => $this->boolInput($request, 'family_events') ? 1 : 0,
            'channel_mail' => $this->boolInput($request, 'channel_mail') ? 1 : 0,
            'channel_push' => $this->boolInput($request, 'channel_push') ? 1 : 0,
            'minutes' => $minutes,
            'user_id' => $userId,
        ]);

        Response::json($this->loadOrCreate($userId));
    }

    private function boolInput(Request $request, string $key): bool
    {
        $value = $request->input($key, false);
        return $value === true || $value === 1 || $value === '1';
    }

    private function loadOrCreate(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT ' . self::COLUMNS . ' FROM notification_preferences WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            $db->prepare('INSERT INTO notification_preferences (user_id) VALUES (:user_id)')
                ->execute(['user_id' => $userId]);
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch();
        }

        return [
            'own_events' => (bool) $row['own_events'],
            'family_events' => (bool) $row['family_events'],
            'channel_mail' => (bool) $row['channel_mail'],
            'channel_push' => (bool) $row['channel_push'],
            'reminder_default_minutes' => (int) $row['reminder_default_minutes'],
        ];
    }
}
