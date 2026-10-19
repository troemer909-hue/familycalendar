<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Enthält den Teil der Kalenderquellen-Synchronisierung, der NICHT vom
 * eingeloggten Benutzer (TenantContext) abhängt, damit er sowohl aus
 * CalendarSourceController (ein Request, ein Mandant) als auch aus
 * bin/sync-calendar-sources.php (Cron-Job, alle Mandanten, kein Request)
 * aufgerufen werden kann.
 */
final class CalendarSync
{
    public static function fetchUrl(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} von der Kalenderquelle.");
        }

        return (string) $body;
    }

    /**
     * Lädt + parst eine stream_ics-Quelle und schreibt die Termine fort.
     * $source ist eine Zeile aus calendar_sources (id, family_id,
     * source_url, family_member_id, default_reminder_minutes) - läuft bei
     * jedem (auch künftigen, über den Cron-Job nachgezogenen) Sync erneut
     * durch, damit auch neu im Feed auftauchende Termine die bei der
     * Einrichtung gewählte Standard-Erinnerung bekommen.
     */
    public static function syncStreamSource(array $source): int
    {
        $ics = self::fetchUrl((string) $source['source_url']);
        $events = IcsParser::parse($ics);

        return self::importEvents(
            (int) $source['family_id'],
            (int) $source['id'],
            'stream',
            $events,
            $source['family_member_id'] !== null ? (int) $source['family_member_id'] : null,
            $source['default_reminder_minutes'] !== null ? (int) $source['default_reminder_minutes'] : null
        );
    }

    /**
     * Schreibt geparste Termine in "events" (Upsert über calendar_source_id
     * + external_uid) und setzt die Teilnehmer: mit zugeordnetem
     * $assignMemberId nur diese Person (scope=personal), sonst alle
     * aktuellen Mitglieder der Familie (scope=family).
     *
     * $reminderMinutes (optional) legt für jeden Teilnehmer MIT eigenem
     * Login eine Erinnerung an (analog zu EventController::create() bei
     * manuell angelegten Terminen) - Teilnehmer ohne Login (reine Profile)
     * werden stillschweigend übersprungen, es gibt für sie kein Postfach/
     * Gerät zum Benachrichtigen.
     *
     * @param array<int, array{uid:string,title:string,description:?string,location:?string,
     *                          starts_at:string,ends_at:string,all_day:bool}> $events
     */
    public static function importEvents(
        int $familyId,
        int $sourceId,
        string $eventSource,
        array $events,
        ?int $assignMemberId,
        ?int $reminderMinutes = null
    ): int {
        $db = Database::connection();

        $scope = $assignMemberId ? 'personal' : 'family';
        $participantIds = $assignMemberId ? [$assignMemberId] : [];
        if (!$assignMemberId) {
            $members = $db->prepare('SELECT id FROM family_members WHERE family_id = :family_id');
            $members->execute(['family_id' => $familyId]);
            $participantIds = array_column($members->fetchAll(), 'id');
        }

        $upsert = $db->prepare(
            'INSERT INTO events
                (family_id, created_by, title, description, location, starts_at, ends_at,
                 all_day, scope, category, source, external_uid, calendar_source_id)
             VALUES
                (:family_id, NULL, :title, :description, :location, :starts_at, :ends_at,
                 :all_day, :scope, "importiert", :source, :external_uid, :calendar_source_id)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title), description = VALUES(description), location = VALUES(location),
                starts_at = VALUES(starts_at), ends_at = VALUES(ends_at), all_day = VALUES(all_day),
                updated_at = NOW()'
        );
        $findId = $db->prepare(
            'SELECT id FROM events WHERE calendar_source_id = :source_id AND external_uid = :uid'
        );
        $resetParticipants = $db->prepare('DELETE FROM event_participants WHERE event_id = :event_id');
        $addParticipant = $db->prepare(
            'INSERT IGNORE INTO event_participants (event_id, family_member_id) VALUES (:event_id, :member_id)'
        );

        foreach ($events as $event) {
            $upsert->execute([
                'family_id' => $familyId,
                'title' => $event['title'] !== '' ? $event['title'] : '(ohne Titel)',
                'description' => $event['description'] ?: null,
                'location' => $event['location'] ?: null,
                'starts_at' => $event['starts_at'],
                'ends_at' => $event['ends_at'],
                'all_day' => $event['all_day'] ? 1 : 0,
                'scope' => $scope,
                'source' => $eventSource,
                'external_uid' => $event['uid'],
                'calendar_source_id' => $sourceId,
            ]);

            $findId->execute(['source_id' => $sourceId, 'uid' => $event['uid']]);
            $eventId = (int) $findId->fetchColumn();

            $resetParticipants->execute(['event_id' => $eventId]);
            foreach ($participantIds as $memberId) {
                $addParticipant->execute(['event_id' => $eventId, 'member_id' => $memberId]);
            }

            if ($reminderMinutes !== null) {
                self::insertDefaultReminders($db, $eventId, $participantIds, $reminderMinutes);
            }
        }

        return count($events);
    }

    /**
     * Identische Logik zu EventController::insertDefaultReminders() - hier
     * dupliziert statt geteilt, weil CalendarSync bewusst OHNE
     * TenantContext auskommt (läuft auch aus dem Cron-Job, der keinen
     * eingeloggten Nutzer kennt), EventController aber darauf aufbaut.
     */
    private static function insertDefaultReminders(\PDO $db, int $eventId, array $participantIds, int $minutes): void
    {
        $memberIds = array_unique(array_map('intval', $participantIds));
        if (empty($memberIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare("SELECT user_id FROM family_members WHERE id IN ({$placeholders}) AND user_id IS NOT NULL");
        $stmt->execute(array_values($memberIds));
        $userIds = array_unique(array_map('intval', array_column($stmt->fetchAll(), 'user_id')));

        if (empty($userIds)) {
            return;
        }

        $insert = $db->prepare(
            'INSERT INTO reminders (event_id, user_id, remind_before_minutes, sent)
             VALUES (:event_id, :user_id, :minutes, 0)
             ON DUPLICATE KEY UPDATE sent = 0'
        );
        foreach ($userIds as $userId) {
            $insert->execute(['event_id' => $eventId, 'user_id' => $userId, 'minutes' => $minutes]);
        }
    }
}
