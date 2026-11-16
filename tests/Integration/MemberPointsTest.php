<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use Familienkalender\Tests\Support\MailCapture;
use PHPUnit\Framework\TestCase;

final class MemberPointsTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
        MailCapture::reset();
    }

    private function loggedInAdmin(string $email = 'admin@example.test'): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Admin',
            'email' => $email,
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($register['ok'], json_encode($register));
        return new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
    }

    /**
     * Legt ein zweites, eingeladenes Mitglied an (Rolle "member", nicht
     * "admin") und meldet sich mit dessen Login an - für Tests, die
     * Nicht-Admin-Berechtigungen prüfen müssen.
     *
     * @return array{client: \ApiClient, member_id: int}
     */
    private function loggedInChild(\ApiClient $admin, string $name = 'Kind'): array
    {
        $profile = $admin->request('POST', '/api/members', ['display_name' => $name]);
        self::assertTrue($profile['ok'], json_encode($profile));
        $memberId = (int) $profile['data']['id'];

        $invite = $admin->request('POST', "/api/members/{$memberId}/invite", ['email' => "{$name}@example.test"]);
        self::assertTrue($invite['ok'], json_encode($invite));

        $token = MailCapture::latestToken();
        $accept = (new \ApiClient(ApiServer::baseUrl(), null))->request('POST', '/api/auth/invite/accept', [
            'token' => $token,
            'password' => 'kind-passwort',
        ]);
        self::assertTrue($accept['ok'], json_encode($accept));

        return [
            'client' => new \ApiClient(ApiServer::baseUrl(), $accept['data']['token']),
            'member_id' => $memberId,
        ];
    }

    private function createRewardedTask(\ApiClient $admin, int $points = 10, string $title = 'Müll wegbringen'): int
    {
        $list = $admin->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        self::assertTrue($list['ok'], json_encode($list));

        $task = $admin->request('POST', "/api/task-lists/{$list['data']['id']}/tasks", [
            'title' => $title,
            'reward_points' => $points,
        ]);
        self::assertTrue($task['ok'], json_encode($task));

        return (int) $task['data']['id'];
    }

    public function testTaskCompletionCreditsPoints(): void
    {
        $admin = $this->loggedInAdmin();
        $taskId = $this->createRewardedTask($admin, 10);

        $admin->request('PUT', "/api/tasks/{$taskId}", ['is_done' => true]);

        $points = $admin->request('GET', '/api/member-points');
        self::assertTrue($points['ok'], json_encode($points));
        self::assertCount(1, $points['data']);
        self::assertSame(10, $points['data'][0]['balance']);
    }

    public function testTaskRevertReversesPoints(): void
    {
        $admin = $this->loggedInAdmin();
        $taskId = $this->createRewardedTask($admin, 10);

        $admin->request('PUT', "/api/tasks/{$taskId}", ['is_done' => true]);
        $afterComplete = $admin->request('GET', '/api/member-points');
        self::assertSame(10, $afterComplete['data'][0]['balance']);

        // Rückgängig machen - muss die Gutschrift per Stornobuchung
        // wieder auf 0 bringen (nicht den Ledger-Eintrag löschen).
        $admin->request('PUT', "/api/tasks/{$taskId}", ['is_done' => false]);
        $afterRevert = $admin->request('GET', '/api/member-points');
        self::assertTrue($afterRevert['ok'], json_encode($afterRevert));
        self::assertSame(0, $afterRevert['data'][0]['balance']);

        $history = $admin->request('GET', "/api/member-points/{$afterRevert['data'][0]['member_id']}/history");
        self::assertTrue($history['ok'], json_encode($history));
        self::assertCount(2, $history['data'], 'Gutschrift + Stornobuchung müssen beide im Verlauf stehen.');
    }

    public function testPointsGoToWhoeverCompletesRegardlessOfAssignment(): void
    {
        // Bewusste Designentscheidung (Issue #57): Punkte gehen an done_by
        // (wer die Aufgabe tatsächlich erledigt hat), nicht an
        // assigned_member_id - done_by ist immer die einloggte Person, die
        // den PUT-Request mit is_done=true schickt.
        $admin = $this->loggedInAdmin();
        $child = $this->loggedInChild($admin, 'Anna');

        $list = $admin->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        $task = $admin->request('POST', "/api/task-lists/{$list['data']['id']}/tasks", [
            'title' => 'Geschirrspüler ausräumen',
            'assigned_member_id' => $child['member_id'],
            'reward_points' => 5,
        ]);
        $taskId = (int) $task['data']['id'];

        // Der Admin (NICHT die zugewiesene Person) erledigt die Aufgabe.
        $admin->request('PUT', "/api/tasks/{$taskId}", ['is_done' => true]);

        $points = $admin->request('GET', '/api/member-points');
        $byName = [];
        foreach ($points['data'] as $entry) {
            $byName[$entry['display_name']] = $entry['balance'];
        }
        self::assertSame(5, $byName['Admin'] ?? null, 'Punkte müssen an den tatsächlich Erledigenden gehen.');
        self::assertSame(0, $byName['Anna'] ?? 0);
    }

    public function testChildCannotAdjustPoints(): void
    {
        $admin = $this->loggedInAdmin();
        $child = $this->loggedInChild($admin, 'Anna');

        $adjust = $child['client']->request('POST', "/api/member-points/{$child['member_id']}/adjust", [
            'points' => 50,
            'reason' => 'manual_adjustment',
        ]);
        self::assertFalse($adjust['ok']);
        self::assertSame(403, $adjust['status']);
    }

    public function testRedemptionAboveBalanceIsBlocked(): void
    {
        $admin = $this->loggedInAdmin();
        $taskId = $this->createRewardedTask($admin, 10);
        $admin->request('PUT', "/api/tasks/{$taskId}", ['is_done' => true]);

        $points = $admin->request('GET', '/api/member-points');
        $memberId = $points['data'][0]['member_id'];

        $redemption = $admin->request('POST', "/api/member-points/{$memberId}/adjust", [
            'points' => -20,
            'reason' => 'redemption',
        ]);
        self::assertFalse($redemption['ok']);
        self::assertSame(422, $redemption['status']);

        // Guthaben darf dabei nicht verändert worden sein.
        $unchanged = $admin->request('GET', '/api/member-points');
        self::assertSame(10, $unchanged['data'][0]['balance']);
    }

    public function testAdminCanManuallyAdjustPoints(): void
    {
        $admin = $this->loggedInAdmin();
        $child = $this->loggedInChild($admin, 'Anna');

        $adjust = $admin->request('POST', "/api/member-points/{$child['member_id']}/adjust", [
            'points' => 15,
            'reason' => 'manual_adjustment',
        ]);
        self::assertTrue($adjust['ok'], json_encode($adjust));
        self::assertSame(15, $adjust['data']['new_balance']);
    }

    public function testPointHistoryIsAccessibleToOwnerAndAdminButNotOthers(): void
    {
        $admin = $this->loggedInAdmin();
        $anna = $this->loggedInChild($admin, 'Anna');
        $ben = $this->loggedInChild($admin, 'Ben');

        $admin->request('POST', "/api/member-points/{$anna['member_id']}/adjust", [
            'points' => 7,
            'reason' => 'manual_adjustment',
        ]);

        $byAdmin = $admin->request('GET', "/api/member-points/{$anna['member_id']}/history");
        self::assertTrue($byAdmin['ok'], json_encode($byAdmin));
        self::assertCount(1, $byAdmin['data']);

        $bySelf = $anna['client']->request('GET', "/api/member-points/{$anna['member_id']}/history");
        self::assertTrue($bySelf['ok'], json_encode($bySelf));

        $byOther = $ben['client']->request('GET', "/api/member-points/{$anna['member_id']}/history");
        self::assertFalse($byOther['ok']);
        self::assertSame(403, $byOther['status']);
    }

    public function testLedgerSumAggregatesMultipleEntriesCorrectly(): void
    {
        $admin = $this->loggedInAdmin();
        $child = $this->loggedInChild($admin, 'Anna');

        $admin->request('POST', "/api/member-points/{$child['member_id']}/adjust", [
            'points' => 10, 'reason' => 'manual_adjustment',
        ]);
        $admin->request('POST', "/api/member-points/{$child['member_id']}/adjust", [
            'points' => 5, 'reason' => 'manual_adjustment',
        ]);
        $admin->request('POST', "/api/member-points/{$child['member_id']}/adjust", [
            'points' => -3, 'reason' => 'redemption',
        ]);

        $points = $admin->request('GET', '/api/member-points');
        $byId = [];
        foreach ($points['data'] as $entry) {
            $byId[$entry['member_id']] = $entry['balance'];
        }
        self::assertSame(12, $byId[$child['member_id']] ?? null);
    }
}
