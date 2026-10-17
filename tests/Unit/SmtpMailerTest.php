<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\SmtpMailer;
use Familienkalender\Tests\Support\FakeSmtpServer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    private FakeSmtpServer $server;

    protected function setUp(): void
    {
        $this->server = new FakeSmtpServer();
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testSendsAPlainMailWithoutAuth(): void
    {
        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $this->server->port(),
            encryption: 'none',
            username: null,
            password: null,
        );

        $mailer->send('empfaenger@example.test', 'absender@example.test', 'Familienkalender', 'Testbetreff', "Hallo Welt\nZweite Zeile");

        $transcript = $this->server->transcript();
        self::assertStringContainsString('EHLO', $transcript);
        self::assertStringContainsString('MAIL FROM:<absender@example.test>', $transcript);
        self::assertStringContainsString('RCPT TO:<empfaenger@example.test>', $transcript);
        self::assertStringContainsString('DATA', $transcript);
        self::assertStringContainsString('QUIT', $transcript);
        self::assertStringContainsString('Hallo Welt', $transcript);
        self::assertStringContainsString('Zweite Zeile', $transcript);
        self::assertStringNotContainsString('AUTH', $transcript);
    }

    public function testAuthenticatesWhenUsernameIsSet(): void
    {
        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $this->server->port(),
            encryption: 'none',
            username: 'testuser',
            password: 'testpass',
        );

        $mailer->send('empfaenger@example.test', 'absender@example.test', 'Familienkalender', 'Betreff', 'Inhalt');

        $transcript = $this->server->transcript();
        self::assertStringContainsString('AUTH LOGIN', $transcript);
        self::assertStringContainsString(base64_encode('testuser'), $transcript);
        self::assertStringContainsString(base64_encode('testpass'), $transcript);
    }

    public function testDotStuffsLinesStartingWithADot(): void
    {
        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $this->server->port(),
            encryption: 'none',
            username: null,
            password: null,
        );

        // Eine Zeile, die (z.B. zufällig durch einen Link/Code) mit einem
        // Punkt beginnt, darf vom Server nicht als Ende der DATA-Sektion
        // missverstanden werden - siehe Dot-Stuffing in SmtpMailer::send().
        $mailer->send('empfaenger@example.test', 'absender@example.test', 'Familienkalender', 'Betreff', ".bin/token-123\nweitere Zeile");

        $transcript = $this->server->transcript();
        self::assertStringContainsString('..bin/token-123', $transcript);
    }

    public function testThrowsOnConnectionFailure(): void
    {
        $this->expectException(\RuntimeException::class);

        // Port, auf dem garantiert nichts lauscht (Server läuft auf einem
        // anderen Port als diesem).
        $deadPort = $this->server->port() === 54321 ? 54322 : 54321;
        $mailer = new SmtpMailer(
            host: '127.0.0.1',
            port: $deadPort,
            encryption: 'none',
            username: null,
            password: null,
        );

        $mailer->send('empfaenger@example.test', 'absender@example.test', 'Familienkalender', 'Betreff', 'Inhalt');
    }
}
