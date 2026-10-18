<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\BrowserSession;
use Familienkalender\Tests\Support\FrontendServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

/**
 * Testet die Kalender-Ansichten (Monat/Woche/Tag, siehe calendar.php) als
 * echte Browser-Session - nicht nur die reinen Helper-Funktionen
 * (buildCalendarUrl()/sanitizeCalendarBack(), siehe tests/Unit/
 * HelpersTest.php). Deckt damit genau die Fehlerklasse ab, die die
 * Helper-Tests NICHT sehen können: einen Fehler im Template selbst (z.B.
 * eine falsch aufgelöste $_GET['view']-Variable, die bei fehlendem
 * Parameter zu einem Fatal Error statt der Standardansicht führte).
 */
final class CalendarViewsTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    private function registeredSession(): BrowserSession
    {
        $browser = new BrowserSession(FrontendServer::baseUrl());

        $registerPage = $browser->get('/register.php');
        $token = $browser->extractCsrfToken($registerPage['body']);

        $register = $browser->post('/register_submit.php', [
            'csrf_token' => $token,
            'api_base_url' => ApiServer::baseUrl(),
            'family_name' => 'Testfamilie',
            'admin_name' => 'Anna',
            'email' => 'anna@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertSame('calendar.php', $register['location'], 'Registrierung sollte auf calendar.php weiterleiten: ' . $register['body']);

        return $browser;
    }

    public function testCalendarDefaultsToMonthViewWithoutAnyParameters(): void
    {
        $browser = $this->registeredSession();

        $result = $browser->get('/calendar.php');

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('calendar-weekday', $result['body']);
    }

    public function testCalendarWeekViewRendersSevenDayColumnsAsHourlyGrid(): void
    {
        $browser = $this->registeredSession();

        $result = $browser->get('/calendar.php?view=week&date=2026-03-10');

        self::assertSame(200, $result['status']);
        self::assertSame(7, substr_count($result['body'], 'class="week-grid-daylabel'));
        self::assertSame(7, substr_count($result['body'], 'class="week-grid-day-col'));
        self::assertStringContainsString('KW 11', $result['body']);
    }

    public function testCalendarDayViewShowsAgendaAndCreatedEventAppearsOnIt(): void
    {
        $browser = $this->registeredSession();

        $emptyDay = $browser->get('/calendar.php?view=day&date=2026-03-10');
        self::assertStringContainsString('Keine Termine an diesem Tag.', $emptyDay['body']);

        $formPage = $browser->get('/event_form.php?date=2026-03-10&back=view%3Dday%26date%3D2026-03-10');
        $token = $browser->extractCsrfToken($formPage['body']);

        $save = $browser->post('/event_save.php', [
            'csrf_token' => $token,
            'back' => 'view=day&date=2026-03-10',
            'title' => 'Zahnarzt',
            'starts_at' => '2026-03-10T09:00',
            'ends_at' => '2026-03-10T10:00',
            'scope' => 'family',
            'repeat_freq' => 'none',
        ]);
        self::assertSame('calendar.php?view=day&date=2026-03-10', $save['location'], (string) $save['location']);

        $dayWithEvent = $browser->get('/calendar.php?view=day&date=2026-03-10');
        self::assertStringContainsString('Zahnarzt', $dayWithEvent['body']);
        self::assertStringContainsString('09:00', $dayWithEvent['body']);
    }

    public function testUnknownViewFallsBackToMonthInsteadOfErroring(): void
    {
        $browser = $this->registeredSession();

        $result = $browser->get('/calendar.php?view=year');

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('calendar-weekday', $result['body']);
    }
}
