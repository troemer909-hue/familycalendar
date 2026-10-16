<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\GitUpdater;
use PHPUnit\Framework\TestCase;

/**
 * Testet GitUpdater gegen zwei EIGENE, temporäre Git-Repos (nicht gegen
 * dieses Projekt selbst!) - sonst würden Tests echte git-Kommandos gegen
 * den laufenden Entwicklungs-Checkout auslösen. "origin" simuliert GitHub,
 * "checkout" simuliert den Server-Checkout, der aktualisiert wird.
 */
final class GitUpdaterTest extends TestCase
{
    private string $originDir;
    private string $checkoutDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/familienkalender-gitupdater-test-' . bin2hex(random_bytes(4));
        $this->originDir = $base . '/origin';
        $this->checkoutDir = $base . '/checkout';

        mkdir($this->originDir, 0755, true);
        $this->git($this->originDir, ['init', '-b', 'main']);
        $this->git($this->originDir, ['config', 'user.email', 'test@example.test']);
        $this->git($this->originDir, ['config', 'user.name', 'Test']);
        file_put_contents($this->originDir . '/file.txt', "v1\n");
        $this->git($this->originDir, ['add', 'file.txt']);
        $this->git($this->originDir, ['commit', '-m', 'erster Commit']);

        $this->git($base, ['clone', $this->originDir, $this->checkoutDir]);
        $this->git($this->checkoutDir, ['config', 'user.email', 'test@example.test']);
        $this->git($this->checkoutDir, ['config', 'user.name', 'Test']);
    }

    protected function tearDown(): void
    {
        $this->removeDir(dirname($this->originDir));
    }

    public function testIsAvailableIsFalseWithoutGitDirectory(): void
    {
        $plainDir = sys_get_temp_dir() . '/familienkalender-no-git-' . bin2hex(random_bytes(4));
        mkdir($plainDir);

        self::assertFalse(GitUpdater::isAvailable($plainDir));

        rmdir($plainDir);
    }

    public function testIsAvailableIsTrueForAGitCheckout(): void
    {
        self::assertTrue(GitUpdater::isAvailable($this->checkoutDir));
    }

    public function testCheckStatusDetectsPendingUpdateOnOrigin(): void
    {
        $updater = new GitUpdater($this->checkoutDir);
        $before = $updater->checkStatus('main');
        self::assertTrue($before['up_to_date']);

        $this->commitNewFileOnOrigin();

        $after = $updater->checkStatus('main');
        self::assertFalse($after['up_to_date']);
        self::assertNotSame($after['current_commit'], $after['remote_commit']);
    }

    public function testUpdateFastForwardsToTheNewestOriginCommit(): void
    {
        $updater = new GitUpdater($this->checkoutDir);
        $oldHead = trim((string) shell_exec('git -C ' . escapeshellarg($this->checkoutDir) . ' rev-parse HEAD'));

        $this->commitNewFileOnOrigin();

        $result = $updater->update('main');

        self::assertSame($oldHead, $result['old_commit']);
        self::assertNotSame($oldHead, $result['new_commit']);
        self::assertFileExists($this->checkoutDir . '/file2.txt');

        $status = $updater->checkStatus('main');
        self::assertTrue($status['up_to_date']);
    }

    public function testUpdateThrowsWhenLocalCheckoutHasDivergedCommits(): void
    {
        // Lokale, abweichende Commits auf dem Server dürfen NIE
        // stillschweigend überschrieben werden - ff-only muss hier
        // kontrolliert scheitern statt z.B. per reset --hard zu verwerfen.
        file_put_contents($this->checkoutDir . '/local-only.txt', "lokal\n");
        $this->git($this->checkoutDir, ['add', 'local-only.txt']);
        $this->git($this->checkoutDir, ['commit', '-m', 'lokale Änderung']);

        $this->commitNewFileOnOrigin();

        $updater = new GitUpdater($this->checkoutDir);

        $this->expectException(\RuntimeException::class);
        $updater->update('main');
    }

    public function testUpdateThrowsWithoutGitDirectory(): void
    {
        $plainDir = sys_get_temp_dir() . '/familienkalender-no-git-' . bin2hex(random_bytes(4));
        mkdir($plainDir);

        $updater = new GitUpdater($plainDir);

        $this->expectException(\RuntimeException::class);
        $updater->update('main');

        rmdir($plainDir);
    }

    private function commitNewFileOnOrigin(): void
    {
        file_put_contents($this->originDir . '/file2.txt', "v2\n");
        $this->git($this->originDir, ['add', 'file2.txt']);
        $this->git($this->originDir, ['commit', '-m', 'zweiter Commit']);
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): void
    {
        $command = 'git ' . implode(' ', array_map('escapeshellarg', $args));
        $output = [];
        $exitCode = 0;
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            self::fail("git-Setup-Kommando fehlgeschlagen ({$command}): " . implode("\n", $output));
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
