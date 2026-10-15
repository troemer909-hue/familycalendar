<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * Erinnerungen sind bewusst PERSÖNLICH: jeder Login-Nutzer legt sich seine
 * eigenen Erinnerungen zu einem (für ihn sichtbaren) Termin an - unabhängig
 * davon, wer den Termin erstellt hat. Der tatsächliche Versand passiert
 * nicht hier, sondern im Cron-Job bin/send-reminders.php (Shared Hosting
 * kennt keine Hintergrund-Jobs innerhalb eines Requests).
 */
final class ReminderController
{
    /**
     * GET /api/events/{id}/reminders
     * Eigene Erinnerungen zu einem Termin.
     */
    public function index(Request $request): void
    {
        $eventId = (int) $request->params['id'];
        $this->assertEventVisible($eventId);

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, remind_before_minutes, sent
             FROM reminders
             WHERE event_id = :event_id AND user_id = :user_id
             ORDER BY remind_before_minutes ASC'
        );
        $stmt->execute(['event_id' => $eventId, 'user_id' => TenantContext::userId()]);

        Response::json($stmt->fetchAll());
    }

    /**
     * POST /api/events/{id}/reminders
     * Body: { remind_before_minutes }
     */
    public function create(Request $request): void
    {
        $eventId = (int) $request->params['id'];
        $this->assertEventVisible($eventId);

        $minutes = (int) $request->input('remind_before_minutes', 0);
        if ($minutes < 1 || $minutes > 10080) {
            Response::error('remind_before_minutes muss zwischen 1 und 10080 (7 Tage) liegen.', 422);
        }

        $db = Database::connection();
        $userId = TenantContext::userId();

        // "sent" zurücksetzen, falls dieselbe Erinnerung zuvor schon gefeuert
        // wurde (z.B. nach Terminverschiebung erneut angelegt).
        $db->prepare(
            'INSERT INTO reminders (event_id, user_id, remind_before_minutes, sent)
             VALUES (:event_id, :user_id, :minutes, 0)
             ON DUPLICATE KEY UPDATE sent = 0'
        )->execute([
            'event_id' => $eventId,
            'user_id' => $userId,
            'minutes' => $minutes,
        ]);

        $idStmt = $db->prepare(
            'SELECT id FROM reminders WHERE event_id = :event_id AND user_id = :user_id AND remind_before_minutes = :minutes'
        );
        $idStmt->execute(['event_id' => $eventId, 'user_id' => $userId, 'minutes' => $minutes]);

        Response::json(['id' => (int) $idStmt->fetchColumn()], 201);
    }

    /**
     * DELETE /api/reminders/{id}
     */
    public function destroy(Request $request): void
    {
        $id = (int) $request->params['id'];

        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM reminders WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => TenantContext::userId()]);

        if ($stmt->rowCount() === 0) {
            Response::error('Erinnerung nicht gefunden.', 404);
        }

        Response::json(['status' => 'deleted']);
    }

    /** Mandantengrenze: Termin muss zur eigenen Familie gehören. */
    private function assertEventVisible(int $eventId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM events WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $eventId, 'family_id' => TenantContext::familyId()]);

        if (!$stmt->fetch()) {
            Response::error('Termin nicht gefunden.', 404);
        }
    }
}
