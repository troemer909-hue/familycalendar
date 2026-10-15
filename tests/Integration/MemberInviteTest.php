<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use Familienkalender\Tests\Support\MailCapture;
use PHPUnit\Framework\TestCase;

final class MemberInviteTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
        MailCapture::reset();
    }

    private function adminClient(): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'sicheres-passwort',
        ]);

        return new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
    }

    private function createProfileMember(\ApiClient $admin, string $name = 'Kind'): int
    {
        $res = $admin->request('POST', '/api/members', ['display_name' => $name]);
        self::assertTrue($res['ok'], json_encode($res));
        return (int) $res['data']['id'];
    }

    public function testInvitingAProfileSendsAnInviteAndAcceptingItCreatesLoginAndLogsIn(): void
    {
        $admin = $this->adminClient();
        $memberId = $this->createProfileMember($admin);

        $invite = $admin->request('POST', "/api/members/{$memberId}/invite", ['email' => 'kind@example.test']);
        self::assertTrue($invite['ok'], json_encode($invite));

        // Den rohen Token gibt es nur per Mail, nicht über die API - für
        // den Test direkt aus der DB lesen (Hash ist nicht umkehrbar).
        $token = MailCapture::latestToken();

        $accept = (new \ApiClient(ApiServer::baseUrl(), null))->request('POST', '/api/auth/invite/accept', [
            'token' => $token,
            'password' => 'neues-passwort',
        ]);
        self::assertTrue($accept['ok'], json_encode($accept));
        self::assertArrayHasKey('token', $accept['data'], 'Nach Annahme der Einladung direkt eingeloggt sein.');

        $members = $admin->request('GET', '/api/members');
        $invited = array_values(array_filter($members['data'], static fn (array $m) => (int) $m['id'] === $memberId))[0];
        self::assertSame('login', $invited['member_type']);

        // Mit dem neuen Login erneut anmelden muss ebenfalls funktionieren.
        $login = (new \ApiClient(ApiServer::baseUrl(), null))->request('POST', '/api/auth/login', [
            'email' => 'kind@example.test', 'password' => 'neues-passwort',
        ]);
        self::assertTrue($login['ok']);
    }

    public function testInvitingAnAlreadyLoggedInMemberIsRejected(): void
    {
        $admin = $this->adminClient();

        $members = $admin->request('GET', '/api/members');
        $adminMemberId = $members['data'][0]['id']; // der Admin selbst hat bereits ein Login

        $invite = $admin->request('POST', "/api/members/{$adminMemberId}/invite", ['email' => 'egal@example.test']);

        self::assertFalse($invite['ok']);
        self::assertSame(409, $invite['status']);
    }

    public function testInvitingWithAnAlreadyUsedEmailIsRejected(): void
    {
        $admin = $this->adminClient();
        $memberId = $this->createProfileMember($admin);

        // admin@example.test gehört schon zum eigenen Account.
        $invite = $admin->request('POST', "/api/members/{$memberId}/invite", ['email' => 'admin@example.test']);

        self::assertFalse($invite['ok']);
        self::assertSame(409, $invite['status']);
    }

    public function testExpiredOrUnknownInviteTokenIsRejected(): void
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);

        $accept = $anonymous->request('POST', '/api/auth/invite/accept', [
            'token' => 'nicht-existierender-token',
            'password' => 'irgendein-passwort',
        ]);

        self::assertFalse($accept['ok']);
        self::assertSame(422, $accept['status']);
    }

    public function testReInvitingInvalidatesThePreviousToken(): void
    {
        $admin = $this->adminClient();
        $memberId = $this->createProfileMember($admin);

        $admin->request('POST', "/api/members/{$memberId}/invite", ['email' => 'kind@example.test']);
        $firstToken = MailCapture::latestToken();

        // Erneut einladen (z.B. weil die erste Mail nicht ankam).
        MailCapture::reset();
        $admin->request('POST', "/api/members/{$memberId}/invite", ['email' => 'kind@example.test']);

        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $acceptWithOldToken = $anonymous->request('POST', '/api/auth/invite/accept', [
            'token' => $firstToken, 'password' => 'irgendein-passwort',
        ]);

        self::assertFalse($acceptWithOldToken['ok'], 'Der erste Token muss nach erneuter Einladung ungültig sein.');
    }
}
