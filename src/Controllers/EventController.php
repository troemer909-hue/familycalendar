<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\RRule;
use Familienkalender\Core\TenantContext;

final class EventController
{
    /**
     * GET /api/events?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Liefert alle Termine der eigenen Familie im Zeitraum – automatisch
     * auf family_id begrenzt, unabhängig davon, wer der Ersteller ist
     * (Kernanforderung: alle Termine für alle sichtbar).
     */
    public function index(Request $request): void
    {
        $from = (string) ($request->query['from'] ?? date('Y-m-d'));
        $to = (string) ($request->query['to'] ?? date('Y-m-d', strtotime('+30 days')));

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT e.id, e.title, e.description, e.location, e.starts_at, e.ends_at,
                    e.all_day, e.scope, e.recurrence_rule, e.recurrence_parent_id, e.category, e.source,
                    e.created_by,
                    GROUP_CONCAT(ep.family_member_id) AS participant_ids
             FROM events e
             LEFT JOIN event_participants ep ON ep.event_id = e.id
             WHERE e.family_id = :family_id
               AND e.starts_at < :to
               AND e.ends_at >= :from
             GROUP BY e.id
             ORDER BY e.starts_at ASC'
        );
        $stmt->execute([
            'family_id' => TenantContext::familyId(),
            'from' => $from . ' 00:00:00',
            'to' => $to . ' 23:59:59',
        ]);

        $events = array_map(static function (array $row): array {
            $row['participant_ids'] = $row['participant_ids']
                ? array_map('intval', explode(',', $row['participant_ids']))
                : [];
            $row['all_day'] = (bool) $row['all_day'];
            return $row;
        }, $stmt->fetchAll());

        Response::json($events);
    }

    /**
     * POST /api/events
     * Body: { title, starts_at, ends_at, all_day?, scope, participant_ids[]?,
     *         description?, location?, recurrence_rule?, category? }
     * scope = 'family' -> für die ganze Familie, 'personal' -> participant_ids Pflicht.
     */
    public function create(Request $request): void
    {
        $title = trim((string) $request->input('title', ''));
        $startsAt = (string) $request->input('starts_at', '');
        $endsAt = (string) $request->input('ends_at', '');
        $scope = (string) $request->input('scope', 'personal');
        $participantIds = $request->input('participant_ids', []);
        $recurrenceRule = trim((string) $request->input('recurrence_rule', '')) ?: null;

        if ($title === '' || $startsAt === '' || $endsAt === '') {
            Response::error('title, starts_at und ends_at sind erforderlich.', 422);
        }
        if (!in_array($scope, ['personal', 'family'], true)) {
            Response::error('scope muss "personal" oder "family" sein.', 422);
        }
        if ($scope === 'personal' && empty($participantIds)) {
            Response::error('Bei scope "personal" muss mindestens eine Person zugeordnet werden.', 422);
        }

        // Wiederholungsregel vorab validieren/auflösen, BEVOR die Transaktion
        // beginnt - ein ungültiges RRULE soll gar keinen Termin anlegen.
        $occurrences = [[
            'starts_at' => new \DateTimeImmutable($startsAt),
            'ends_at' => new \DateTimeImmutable($endsAt),
        ]];
        if ($recurrenceRule !== null) {
            try {
                $occurrences = RRule::expand($recurrenceRule, new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt));
            } catch (\Throwable $e) {
                Response::error('Wiederholungsregel ungültig: ' . $e->getMessage(), 422);
            }
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO events
                    (family_id, created_by, title, description, location, starts_at, ends_at,
                     all_day, scope, recurrence_rule, category, source)
                 VALUES
                    (:family_id, :created_by, :title, :description, :location, :starts_at, :ends_at,
                     :all_day, :scope, :recurrence_rule, :category, "manual")'
            );
            $stmt->execute([
                'family_id' => TenantContext::familyId(),
                'created_by' => TenantContext::familyMemberId(),
                'title' => $title,
                'description' => $request->input('description'),
                'location' => $request->input('location'),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'all_day' => $request->input('all_day', false) ? 1 : 0,
                'scope' => $scope,
                'recurrence_rule' => $recurrenceRule,
                'category' => $request->input('category'),
            ]);
            $eventId = (int) $db->lastInsertId();

            if ($scope === 'family') {
                // Familientermin: alle aktuellen Mitglieder als Teilnehmer eintragen,
                // damit Filter "nur Papas Termine" konsistent funktioniert.
                $members = $db->prepare('SELECT id FROM family_members WHERE family_id = :family_id');
                $members->execute(['family_id' => TenantContext::familyId()]);
                $participantIds = array_column($members->fetchAll(), 'id');
            }

            $this->syncParticipants($db, $eventId, $participantIds);

            // Wiederkehrende Termine: das erste Vorkommen IST der soeben
            // angelegte Master-Termin selbst. Ab dem zweiten Vorkommen
            // werden eigenständige Instanzen angelegt (recurrence_parent_id
            // = Master), die sich einzeln bearbeiten/löschen lassen, ohne
            // die restliche Serie zu beeinflussen. Löschen des Masters
            // löscht per ON DELETE CASCADE automatisch alle Instanzen mit
            // (siehe deleteSeries()).
            $childStmt = $db->prepare(
                'INSERT INTO events
                    (family_id, created_by, title, description, location, starts_at, ends_at,
                     all_day, scope, recurrence_parent_id, category, source)
                 VALUES
                    (:family_id, :created_by, :title, :description, :location, :starts_at, :ends_at,
                     :all_day, :scope, :parent_id, :category, "manual")'
            );
            foreach (array_slice($occurrences, 1) as $occurrence) {
                $childStmt->execute([
                    'family_id' => TenantContext::familyId(),
                    'created_by' => TenantContext::familyMemberId(),
                    'title' => $title,
                    'description' => $request->input('description'),
                    'location' => $request->input('location'),
                    'starts_at' => $occurrence['starts_at']->format('Y-m-d H:i:s'),
                    'ends_at' => $occurrence['ends_at']->format('Y-m-d H:i:s'),
                    'all_day' => $request->input('all_day', false) ? 1 : 0,
                    'scope' => $scope,
                    'parent_id' => $eventId,
                    'category' => $request->input('category'),
                ]);
                $this->syncParticipants($db, (int) $db->lastInsertId(), $participantIds);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Termin anlegen fehlgeschlagen: ' . $e->getMessage());
            Response::error('Termin anlegen fehlgeschlagen.', 500);
        }

        Response::json(['id' => $eventId, 'occurrences' => count($occurrences)], 201);
    }

    /**
     * GET /api/events/{id}
     * Einzelnen Termin laden (für Bearbeiten-Formulare). Lesend für alle
     * Familienmitglieder erlaubt (Sichtbarkeitsregel: alle Termine für alle
     * sichtbar) – die Schreibrechte werden erst bei update()/delete() geprüft.
     */
    public function show(Request $request): void
    {
        $eventId = (int) $request->params['id'];

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT e.id, e.title, e.description, e.location, e.starts_at, e.ends_at,
                    e.all_day, e.scope, e.recurrence_rule, e.recurrence_parent_id, e.category, e.source, e.created_by,
                    GROUP_CONCAT(ep.family_member_id) AS participant_ids
             FROM events e
             LEFT JOIN event_participants ep ON ep.event_id = e.id
             WHERE e.id = :id AND e.family_id = :family_id
             GROUP BY e.id'
        );
        $stmt->execute(['id' => $eventId, 'family_id' => TenantContext::familyId()]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('Termin nicht gefunden.', 404);
        }

        $event['participant_ids'] = $event['participant_ids']
            ? array_map('intval', explode(',', $event['participant_ids']))
            : [];
        $event['all_day'] = (bool) $event['all_day'];

        Response::json($event);
    }

    /**
     * PUT /api/events/{id}
     */
    public function update(Request $request): void
    {
        $eventId = (int) $request->params['id'];
        $event = $this->findOwnedEvent($eventId);

        $db = Database::connection();

        $scope = (string) $request->input('scope', $event['scope']);
        if (!in_array($scope, ['personal', 'family'], true)) {
            Response::error('scope muss "personal" oder "family" sein.', 422);
        }

        $fields = [
            'title' => $request->input('title', $event['title']),
            'description' => $request->input('description', $event['description']),
            'location' => $request->input('location', $event['location']),
            'starts_at' => $request->input('starts_at', $event['starts_at']),
            'ends_at' => $request->input('ends_at', $event['ends_at']),
            'all_day' => $request->input('all_day', (bool) $event['all_day']) ? 1 : 0,
            'category' => $request->input('category', $event['category']),
            'scope' => $scope,
        ];

        $stmt = $db->prepare(
            'UPDATE events SET title = :title, description = :description, location = :location,
                starts_at = :starts_at, ends_at = :ends_at, all_day = :all_day, category = :category,
                scope = :scope, updated_at = NOW()
             WHERE id = :id AND family_id = :family_id'
        );
        $stmt->execute($fields + ['id' => $eventId, 'family_id' => TenantContext::familyId()]);

        if ($scope === 'family') {
            // Familientermin: wie bei create() automatisch alle aktuellen
            // Mitglieder als Teilnehmer eintragen, unabhängig davon, was
            // im Formular übermittelt wurde.
            $members = $db->prepare('SELECT id FROM family_members WHERE family_id = :family_id');
            $members->execute(['family_id' => TenantContext::familyId()]);
            $this->syncParticipants($db, $eventId, array_column($members->fetchAll(), 'id'));
        } elseif (array_key_exists('participant_ids', $request->body)) {
            $this->syncParticipants($db, $eventId, $request->input('participant_ids', []));
        }

        Response::json(['status' => 'updated']);
    }

    /**
     * DELETE /api/events/{id}
     */
    public function delete(Request $request): void
    {
        $eventId = (int) $request->params['id'];
        $this->findOwnedEvent($eventId); // wirft 404, falls nicht in eigener Familie

        $db = Database::connection();
        $db->prepare('DELETE FROM events WHERE id = :id AND family_id = :family_id')
            ->execute(['id' => $eventId, 'family_id' => TenantContext::familyId()]);

        Response::json(['status' => 'deleted']);
    }

    /**
     * DELETE /api/events/{id}/series
     * Löscht den GESAMTEN Rest einer Wiederholungsserie (nur möglich auf
     * dem Master-Termin selbst, der die recurrence_rule trägt) - alle
     * materialisierten Instanzen hängen per ON DELETE CASCADE an
     * recurrence_parent_id und verschwinden automatisch mit.
     */
    public function deleteSeries(Request $request): void
    {
        $eventId = (int) $request->params['id'];
        $event = $this->findOwnedEvent($eventId);

        if ($event['recurrence_rule'] === null || $event['recurrence_parent_id'] !== null) {
            Response::error('Dieser Termin ist kein Serien-Master (keine Wiederholungsregel oder selbst nur eine Instanz).', 422);
        }

        $db = Database::connection();
        $db->prepare('DELETE FROM events WHERE id = :id AND family_id = :family_id')
            ->execute(['id' => $eventId, 'family_id' => TenantContext::familyId()]);

        Response::json(['status' => 'deleted']);
    }

    /**
     * Lädt einen Termin und prüft dabei implizit die Mandantengrenze
     * sowie die Bearbeitungsrechte (Ersteller oder Admin, siehe Konzept
     * Abschnitt 5 – Rollen & Rechte).
     */
    private function findOwnedEvent(int $eventId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM events WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $eventId, 'family_id' => TenantContext::familyId()]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::error('Termin nicht gefunden.', 404);
        }

        $isCreator = (int) $event['created_by'] === TenantContext::familyMemberId();
        if (!$isCreator && !TenantContext::isAdmin()) {
            Response::error('Keine Berechtigung, diesen Termin zu bearbeiten.', 403);
        }

        return $event;
    }

    private function syncParticipants(\PDO $db, int $eventId, array $participantIds): void
    {
        $db->prepare('DELETE FROM event_participants WHERE event_id = :event_id')
            ->execute(['event_id' => $eventId]);

        if (empty($participantIds)) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO event_participants (event_id, family_member_id) VALUES (:event_id, :member_id)'
        );
        foreach (array_unique(array_map('intval', $participantIds)) as $memberId) {
            $stmt->execute(['event_id' => $eventId, 'member_id' => $memberId]);
        }
    }
}
