<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class MemberPointsTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
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

    public function testTaskCompletionCreditsPoints(): void
    {
        $client = $this->loggedInAdmin();
        $list = $client->request('POST', '/api/task-lists', ['name' => 'Haushalt']);
        self::assertTrue($list['ok'], json_encode($list));
        $taskId = $client->request('POST', "/api/task-lists/{$list['data']['id']}/tasks", [
            'title' => 'Müll wegbringen', 'reward_points' => 10,
        ])['data']['id'];

        $client->request('PUT', "/api/tasks/{$taskId}", ['is_done' => true]);
        $points = $client->request('GET', '/api/member-points');
        self::assertTrue($points['ok'], json_encode($points));
        self::assertCount(1, $points['data']);
        self::assertSame(10, $points['data'][0]['balance']);
    }
