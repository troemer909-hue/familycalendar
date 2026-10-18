<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\GitUpdater;
use Familienkalender\Core\MigrationRunner;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\TenantContext;

/**
 * "Knopfdruck"-Update: zieht den konfigurierten Branch per `git merge
 * --ff-only` nach und wendet danach alle neuen database/migrations/*.sql
 * automatisch an (siehe GitUpdater, MigrationRunner). Bewusst nur für
 * TenantContext::isPlatformAdmin() UND nur, wenn der Betreiber es per
 * ENABLE_SELF_UPDATE=1 in der .env ausdrücklich freigeschaltet hat (siehe
 * .env.example) - ein Shell-Kommando aus einem Web-Request auszulösen ist
 * die sicherheitskritischste Aktion dieser App, daher standardmäßig AUS.
 */
final class SelfUpdateController
{
    /**
     * GET /api/self-update/status
     * Zeigt an, ob das Feature überhaupt verfügbar ist, den aktuellen
     * Commit und (falls erreichbar) ob es einen neueren Stand auf dem
     * konfigurierten Branch gibt - OHNE etwas zu verändern.
     */
    public function status(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        $enabled = $this->isEnabled();
        $projectRoot = $this->projectRoot();
        $gitAvailable = GitUpdater::isAvailable($projectRoot);

        $result = [
            'enabled' => $enabled,
            'git_available' => $gitAvailable,
            'branch' => $this->branch(),
            'current_commit' => null,
            'remote_commit' => null,
            'up_to_date' => null,
            'error' => null,
        ];

        if ($enabled && $gitAvailable) {
            try {
                $updater = new GitUpdater($projectRoot);
                $status = $updater->checkStatus($this->branch());
                $result['current_commit'] = $status['current_commit'];
                $result['remote_commit'] = $status['remote_commit'];
                $result['up_to_date'] = $status['up_to_date'];
            } catch (\Throwable $e) {
                $result['error'] = $e->getMessage();
            }
        }

        Response::json($result);
    }

    /**
     * POST /api/self-update/run
     * Zieht den neuen Stand und wendet ausstehende Migrationen an.
     * Protokolliert jeden Lauf in self_update_runs (siehe Migration
     * 011_self_update.sql), unabhängig vom Ergebnis.
     */
    public function run(Request $request): void
    {
        TenantContext::requirePlatformAdmin();

        if (!$this->isEnabled()) {
            Response::error('Selbst-Update ist nicht aktiviert (ENABLE_SELF_UPDATE in der .env setzen).', 403);
        }

        $projectRoot = $this->projectRoot();
        $db = Database::connection();
        $oldCommit = null;
        $newCommit = null;
        $migrationsApplied = [];

        try {
            $updater = new GitUpdater($projectRoot);
            $gitResult = $updater->update($this->branch());
            $oldCommit = $gitResult['old_commit'];
            $newCommit = $gitResult['new_commit'];

            $runner = new MigrationRunner($db, $projectRoot . '/database/migrations');
            $migrationsApplied = $runner->applyPending();

            $this->logRun($db, $oldCommit, $newCommit, $migrationsApplied, true, null);
        } catch (\Throwable $e) {
            $this->logRun($db, $oldCommit, $newCommit, $migrationsApplied, false, $e->getMessage());
            Response::error('Update fehlgeschlagen: ' . $e->getMessage(), 500);
        }

        // Außerhalb des try-Blocks: Response::json() wirft im Capture-Modus
        // (siehe LocalApiClient) selbst eine ResponseCaptured-Exception als
        // Kontrollfluss-Signal - stünde dieser Aufruf noch im try, würde das
        // eigene catch(\Throwable) oben sie fälschlich als echten Fehler
        // abfangen und ein ERFOLGREICHES Update als "fehlgeschlagen" melden.
        Response::json([
            'old_commit' => $oldCommit,
            'new_commit' => $newCommit,
            'migrations_applied' => $migrationsApplied,
            'already_up_to_date' => $oldCommit === $newCommit && $migrationsApplied === [],
        ]);
    }

    private function isEnabled(): bool
    {
        return in_array(strtolower((string) Env::get('ENABLE_SELF_UPDATE', '0')), ['1', 'true', 'on'], true);
    }

    private function branch(): string
    {
        return Env::get('SELF_UPDATE_BRANCH', 'main') ?: 'main';
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param list<string> $migrationsApplied */
    private function logRun(
        \PDO $db,
        ?string $oldCommit,
        ?string $newCommit,
        array $migrationsApplied,
        bool $success,
        ?string $message
    ): void {
        $db->prepare(
            'INSERT INTO self_update_runs
                (triggered_by_user_id, from_commit, to_commit, migrations_applied, success, message)
             VALUES (:user_id, :from_commit, :to_commit, :migrations_applied, :success, :message)'
        )->execute([
            'user_id' => TenantContext::userId(),
            'from_commit' => $oldCommit,
            'to_commit' => $newCommit,
            'migrations_applied' => $migrationsApplied === [] ? null : implode(', ', $migrationsApplied),
            'success' => $success ? 1 : 0,
            'message' => $message,
        ]);
    }
}
