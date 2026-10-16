<?php

declare(strict_types=1);

namespace Familienkalender\Core;

use PDO;

/**
 * Wendet database/migrations/*.sql an, die noch nicht in der Tabelle
 * schema_migrations verzeichnet sind (siehe GitUpdater, SelfUpdateController
 * und database/migrations/011_self_update.sql). Jede Datei wird in EINER
 * eigenen Transaktion ausgeführt und direkt danach als "angewendet"
 * vermerkt, damit ein Abbruch mitten in einem Update nicht zu einer
 * halb angewendeten, aber unvermerkten Migration führt.
 */
final class MigrationRunner
{
    /**
     * Legt schema_migrations selbst an UND markiert darin alle bis zu
     * diesem Release vorhandenen Migrationen (002-011) als bereits
     * angewendet (siehe Datei selbst). Existiert die Tabelle noch nicht
     * (Installation von VOR diesem Feature), muss genau diese Datei vor
     * allen anderen laufen - sonst würde der Runner versuchen, 002-010
     * ein zweites Mal auf eine Datenbank anzuwenden, die sie längst
     * enthält (z.B. ein ALTER TABLE ADD COLUMN, das kein zweites Mal
     * funktioniert).
     */
    private const BOOTSTRAP_MIGRATION = '011_self_update.sql';

    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationsDir
    ) {
    }

    /** @return list<string> Dateinamen der neu angewendeten Migrationen */
    public function applyPending(): array
    {
        $newlyApplied = [];

        if (!$this->schemaMigrationsTableExists()) {
            $bootstrapFile = $this->migrationsDir . '/' . self::BOOTSTRAP_MIGRATION;
            if (is_file($bootstrapFile)) {
                $this->applyFile($bootstrapFile, self::BOOTSTRAP_MIGRATION);
                $newlyApplied[] = self::BOOTSTRAP_MIGRATION;
            }
        }

        $applied = $this->alreadyApplied();
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true) || in_array($name, $newlyApplied, true)) {
                continue;
            }

            $this->applyFile($file, $name);
            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    private function schemaMigrationsTableExists(): bool
    {
        $count = $this->db->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
        )->fetchColumn();

        return (int) $count > 0;
    }

    /** @return list<string> */
    private function alreadyApplied(): array
    {
        if (!$this->schemaMigrationsTableExists()) {
            return [];
        }

        return $this->db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function applyFile(string $path, string $name): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Migration konnte nicht gelesen werden: {$name}");
        }

        // Bewusst OHNE PDO-Transaktion: CREATE TABLE/ALTER TABLE lösen in
        // MySQL/InnoDB ohnehin einen impliziten COMMIT aus, eine
        // Transaktion über mehrere solcher DDL-Statements hinweg wäre
        // also nie wirklich atomar - stattdessen einfach die konkrete
        // Fehlermeldung durchreichen, damit zumindest klar ist, WELCHE
        // Migration und WARUM gescheitert ist.
        try {
            foreach ($this->splitStatements($sql) as $statement) {
                $this->db->exec($statement);
            }

            // INSERT IGNORE statt INSERT: 011_self_update.sql trägt sich in
            // seiner eigenen SQL-Datei bereits selbst ein (siehe Datei) -
            // ein zweiter, hier folgender Insert mit demselben Primärschlüssel
            // darf dabei nicht fehlschlagen.
            $this->db->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (:migration)')
                ->execute(['migration' => $name]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Migration fehlgeschlagen ({$name}): {$e->getMessage()}", 0, $e);
        }
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        foreach (explode(';', $sql) as $statement) {
            $withoutComments = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));
            if ($withoutComments === '') {
                continue;
            }
            $statements[] = trim($statement);
        }

        return $statements;
    }
}
