<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Core\MigrationRunner;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private PDO $db;
    private string $migrationsDir;

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();
        $this->migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
    }

    public function testFreshlySeededDatabaseHasNothingPending(): void
    {
        // database/schema.sql trägt alle bisherigen Migrationen bereits
        // selbst in schema_migrations ein (siehe Migration
        // 011_self_update.sql) - ein frisch aufgesetztes Schema hat daher
        // nichts mehr anzuwenden.
        $runner = new MigrationRunner($this->db, $this->migrationsDir);

        self::assertSame([], $runner->applyPending());
    }

    public function testBootstrapsSchemaMigrationsTableOnPreExistingInstall(): void
    {
        // Simuliert eine Installation von VOR diesem Feature: die Tabellen
        // 002-010 existieren bereits (durch schema.sql), aber
        // schema_migrations/self_update_runs noch nicht.
        $this->db->exec('DROP TABLE IF EXISTS self_update_runs');
        $this->db->exec('DROP TABLE IF EXISTS schema_migrations');

        $runner = new MigrationRunner($this->db, $this->migrationsDir);
        $applied = $runner->applyPending();

        // Nur die Bootstrap-Migration selbst wird ausgeführt - 002-010
        // dürfen NICHT erneut laufen (sie würden an bereits vorhandenen
        // Spalten/Schlüsseln scheitern).
        self::assertSame(['011_self_update.sql', '012_shorten_indexed_varchars.sql'], $applied);

        $recorded = $this->db->query('SELECT migration FROM schema_migrations ORDER BY migration')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('002_calendar_source_sync.sql', $recorded);
        self::assertContains('011_self_update.sql', $recorded);
    }

    public function testDoesNotReapplyAlreadyAppliedMigration(): void
    {
        $runner = new MigrationRunner($this->db, $this->migrationsDir);
        self::assertSame([], $runner->applyPending());

        // Ein zweiter Lauf direkt danach darf nichts erneut anwenden.
        self::assertSame([], $runner->applyPending());
    }
}
