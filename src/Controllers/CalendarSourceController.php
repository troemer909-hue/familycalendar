<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\CalendarSync;
use Familienkalender\Core\CsvImporter;
use Familienkalender\Core\Database;
use Familienkalender\Core\IcsParser;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;
use PDO;

/**
 * Verwaltet externe Kalenderquellen (calendar_sources): einmaliger
 * ICS/CSV-Import ODER laufendes ICS-Abonnement (stream_ics). Importierte
 * Termine landen ganz normal in "events" (source = import/stream,
 * external_uid + calendar_source_id für den Abgleich bei erneutem Sync).
 */
final class CalendarSourceController
{
    /**
     * GET /api/calendar-sources
     */
    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, name, type, source_url, sync_interval_minutes, last_synced_at, status, family_member_id
             FROM calendar_sources
             WHERE family_id = :family_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['family_id' => TenantContext::familyId()]);

        Response::json($stmt->fetchAll());
    }

    /**
     * POST /api/calendar-sources/import-ics
     * Body: { name, ics_content, family_member_id? }
     * Einmaliger Import einer .ics-Datei (z.B. Export aus Outlook/Google).
     */
    public function importIcs(Request $request): void
    {
        TenantContext::requireAdmin();

        $name = trim((string) $request->input('name', ''));
        $icsContent = (string) $request->input('ics_content', '');
        if ($name === '' || $icsContent === '') {
            Response::error('name und ics_content sind erforderlich.', 422);
        }

        try {
            $events = IcsParser::parse($icsContent);
        } catch (\Throwable $e) {
            Response::error('ICS-Datei konnte nicht gelesen werden: ' . $e->getMessage(), 422);
        }

        $memberId = $request->input('family_member_id');
        $sourceId = $this->createSourceRow('import_ics', $name, null, null, $memberId);
        $count = $this->importEvents($sourceId, 'import', $events, $memberId);
        $this->markSynced($sourceId);

        Response::json(['id' => $sourceId, 'imported' => $count], 201);
    }

    /**
     * POST /api/calendar-sources/import-csv
     * Body: { name, csv_content, family_member_id? }
     * Einmaliger Import einer CSV-Tabelle (Spalten: Titel, Beginn, Ende, ...).
     */
    public function importCsv(Request $request): void
    {
        TenantContext::requireAdmin();

        $name = trim((string) $request->input('name', ''));
        $csvContent = (string) $request->input('csv_content', '');
        if ($name === '' || $csvContent === '') {
            Response::error('name und csv_content sind erforderlich.', 422);
        }

        try {
            $events = CsvImporter::parse($csvContent);
        } catch (\Throwable $e) {
            Response::error('CSV-Datei konnte nicht gelesen werden: ' . $e->getMessage(), 422);
        }

        $memberId = $request->input('family_member_id');
        $sourceId = $this->createSourceRow('import_csv', $name, null, null, $memberId);
        $count = $this->importEvents($sourceId, 'import', $events, $memberId);
        $this->markSynced($sourceId);

        Response::json(['id' => $sourceId, 'imported' => $count], 201);
    }

    /**
     * POST /api/calendar-sources/stream
     * Body: { name, source_url, sync_interval_minutes?, family_member_id? }
     * Legt ein laufendes ICS-Abonnement an und synchronisiert sofort einmal.
     * Für die wiederkehrende Aktualisierung siehe bin/sync-calendar-sources.php
     * (per Cron-Job aufzurufen - Shared Hosting kennt keine Hintergrund-Jobs).
     */
    public function createStream(Request $request): void
    {
        TenantContext::requireAdmin();

        $name = trim((string) $request->input('name', ''));
        $url = trim((string) $request->input('source_url', ''));
        $interval = (int) $request->input('sync_interval_minutes', 60);

        if ($name === '' || $url === '') {
            Response::error('name und source_url sind erforderlich.', 422);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            Response::error('source_url ist keine gültige http(s)-Adresse.', 422);
        }

        $memberId = $request->input('family_member_id');
        $sourceId = $this->createSourceRow('stream_ics', $name, $url, max(5, $interval), $memberId);

        $this->runSync($sourceId);

        Response::json(['id' => $sourceId], 201);
    }

    /**
     * POST /api/calendar-sources/{id}/sync
     * Manuelles Nachziehen eines Abonnements (zusätzlich zum Cron-Job).
     */
    public function sync(Request $request): void
    {
        TenantContext::requireAdmin();
        $this->runSync((int) $request->params['id']);
        Response::json(['status' => 'synced']);
    }

    /**
     * DELETE /api/calendar-sources/{id}
     * Bereits importierte/synchronisierte Termine bleiben erhalten - nur
     * die Quelle selbst wird entfernt (calendar_source_id wird per FK auf
     * NULL gesetzt, siehe database/schema.sql).
     */
    public function destroy(Request $request): void
    {
        TenantContext::requireAdmin();
        $id = (int) $request->params['id'];

        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM calendar_sources WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $id, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) {
            Response::error('Kalenderquelle nicht gefunden.', 404);
        }

        $db->prepare('DELETE FROM calendar_sources WHERE id = :id')->execute(['id' => $id]);
        Response::json(['status' => 'deleted']);
    }

    private function createSourceRow(string $type, string $name, ?string $url, ?int $interval, mixed $familyMemberId): int
    {
        $db = Database::connection();
        $memberId = $familyMemberId ? $this->assertOwnMember($db, (int) $familyMemberId) : null;

        $db->prepare(
            'INSERT INTO calendar_sources (family_id, family_member_id, name, type, source_url, sync_interval_minutes, status)
             VALUES (:family_id, :family_member_id, :name, :type, :source_url, :interval, "active")'
        )->execute([
            'family_id' => TenantContext::familyId(),
            'family_member_id' => $memberId,
            'name' => $name,
            'type' => $type,
            'source_url' => $url,
            'interval' => $interval,
        ]);

        return (int) $db->lastInsertId();
    }

    private function assertOwnMember(PDO $db, int $memberId): int
    {
        $stmt = $db->prepare('SELECT id FROM family_members WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $memberId, 'family_id' => TenantContext::familyId()]);
        if (!$stmt->fetch()) {
            Response::error('Person nicht gefunden.', 404);
        }
        return $memberId;
    }

    private function markSynced(int $sourceId): void
    {
        Database::connection()
            ->prepare('UPDATE calendar_sources SET last_synced_at = NOW() WHERE id = :id')
            ->execute(['id' => $sourceId]);
    }

    /**
     * Lädt eine stream_ics-Quelle per HTTP, parst sie und schreibt die
     * Termine fort (Upsert über calendar_source_id + external_uid). Wird
     * vom Admin manuell UND vom Cron-Script aufgerufen - deshalb zentral
     * hier statt verteilt in den Controller-Actions.
     */
    private function runSync(int $sourceId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM calendar_sources WHERE id = :id AND family_id = :family_id');
        $stmt->execute(['id' => $sourceId, 'family_id' => TenantContext::familyId()]);
        $source = $stmt->fetch();

        if (!$source) {
            Response::error('Kalenderquelle nicht gefunden.', 404);
        }
        if ($source['type'] !== 'stream_ics') {
            Response::error('Nur Abonnements (stream_ics) können synchronisiert werden.', 422);
        }

        try {
            CalendarSync::syncStreamSource($source);

            $db->prepare('UPDATE calendar_sources SET status = "active", last_synced_at = NOW() WHERE id = :id')
                ->execute(['id' => $sourceId]);
        } catch (\Throwable $e) {
            error_log('Kalender-Sync fehlgeschlagen (source ' . $sourceId . '): ' . $e->getMessage());
            $db->prepare('UPDATE calendar_sources SET status = "error" WHERE id = :id')->execute(['id' => $sourceId]);
            Response::error('Synchronisierung fehlgeschlagen: ' . $e->getMessage(), 502);
        }
    }

    /**
     * @param array<int, array{uid:string,title:string,description:?string,location:?string,
     *                          starts_at:string,ends_at:string,all_day:bool}> $events
     */
    private function importEvents(int $sourceId, string $eventSource, array $events, mixed $assignMemberId): int
    {
        return CalendarSync::importEvents(
            TenantContext::familyId(),
            $sourceId,
            $eventSource,
            $events,
            $assignMemberId ? (int) $assignMemberId : null
        );
    }
}
