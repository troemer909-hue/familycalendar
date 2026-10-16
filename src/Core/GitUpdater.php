<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Selbst-Update per Git ("Knopfdruck"-Update, siehe SelfUpdateController).
 * Bewusst restriktiv gehalten, weil hier zum ersten Mal in dieser App ein
 * Web-Request Shell-Kommandos auf dem Server auslöst:
 *
 * - Nur aktiv, wenn ENABLE_SELF_UPDATE=1 in der .env gesetzt ist (siehe
 *   .env.example) - standardmäßig AUS.
 * - Der Branch-Name kommt ausschließlich aus SELF_UPDATE_BRANCH (.env,
 *   also vom Betreiber selbst gesetzt), NIEMALS aus dem Request - es gibt
 *   keinen Weg, über die API/das Formular einen eigenen Befehl oder
 *   Branch-Namen einzuschleusen.
 * - `git merge --ff-only` statt `git pull`/`reset --hard`: scheitert
 *   kontrolliert ab, sobald es auf dem Server lokale, abweichende Commits
 *   gibt, statt sie stillschweigend zu verwerfen.
 */
final class GitUpdater
{
    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public static function isAvailable(string $projectRoot): bool
    {
        return is_dir($projectRoot . '/.git');
    }

    /**
     * @return array{old_commit: string, new_commit: string, output: string}
     * @throws \RuntimeException wenn ein Schritt fehlschlägt (Fetch, Merge-Konflikt, ...)
     */
    public function update(string $branch): array
    {
        if (!self::isAvailable($this->projectRoot)) {
            throw new \RuntimeException('Kein Git-Checkout auf diesem Server gefunden (.git-Ordner fehlt).');
        }

        $oldCommit = $this->run(['git', 'rev-parse', 'HEAD']);

        $this->run(['git', 'fetch', 'origin', $branch]);
        $mergeOutput = $this->run(['git', 'merge', '--ff-only', 'origin/' . $branch]);

        $newCommit = $this->run(['git', 'rev-parse', 'HEAD']);

        return [
            'old_commit' => trim($oldCommit),
            'new_commit' => trim($newCommit),
            'output' => trim($mergeOutput),
        ];
    }

    /**
     * Prüft, ob es auf dem Remote-Branch neue Commits gibt, ohne sie zu
     * übernehmen (für die Statusanzeige vor dem eigentlichen Update-Klick).
     *
     * @return array{current_commit: string, remote_commit: string, up_to_date: bool}
     */
    public function checkStatus(string $branch): array
    {
        if (!self::isAvailable($this->projectRoot)) {
            throw new \RuntimeException('Kein Git-Checkout auf diesem Server gefunden (.git-Ordner fehlt).');
        }

        $current = trim($this->run(['git', 'rev-parse', 'HEAD']));
        $this->run(['git', 'fetch', 'origin', $branch]);
        $remote = trim($this->run(['git', 'rev-parse', 'origin/' . $branch]));

        return [
            'current_commit' => $current,
            'remote_commit' => $remote,
            'up_to_date' => $current === $remote,
        ];
    }

    /**
     * @param list<string> $args
     */
    private function run(array $args): string
    {
        $command = implode(' ', array_map('escapeshellarg', $args));

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);
        if ($process === false) {
            throw new \RuntimeException('Git-Kommando konnte nicht gestartet werden.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim($stderr !== false && $stderr !== '' ? $stderr : (string) $stdout);
            throw new \RuntimeException("Git-Kommando fehlgeschlagen ({$args[1]}): {$detail}");
        }

        return $stdout !== false ? $stdout : '';
    }
}
