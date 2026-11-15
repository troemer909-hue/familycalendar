<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

final class ShoppingListTest extends TestCase
{
    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        IntegrationDb::freshDatabase();
    }

    private function loggedInClient(string $email = 'admin@example.test'): \ApiClient
    {
        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $register = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Testfamilie',
            'admin_name' => 'Admin',
            'email' => $email,
            'password' => 'sicheres-passwort',
        ]);

        return new \ApiClient(ApiServer::baseUrl(), $register['data']['token']);
    }

    public function testAddCheckAndDeleteItem(): void
    {
        $client = $this->loggedInClient();

        self::assertCount(0, $client->request('GET', '/api/shopping-list')['data']);

        $create = $client->request('POST', '/api/shopping-list', ['label' => 'Milch, 2x']);
        self::assertTrue($create['ok'], json_encode($create));
        $itemId = $create['data']['id'];

        $items = $client->request('GET', '/api/shopping-list');
        self::assertCount(1, $items['data']);
        self::assertSame('Milch, 2x', $items['data'][0]['label']);
        self::assertFalse($items['data'][0]['checked']);
        self::assertSame('Admin', $items['data'][0]['added_by_name']);

        $check = $client->request('PUT', "/api/shopping-list/{$itemId}", ['checked' => true]);
        self::assertTrue($check['ok']);
        self::assertTrue($check['data']['checked']);

        $afterCheck = $client->request('GET', '/api/shopping-list')['data'][0];
        self::assertTrue($afterCheck['checked']);
        self::assertSame('Admin', $afterCheck['checked_by_name']);
        self::assertNotNull($afterCheck['checked_at']);

        $uncheck = $client->request('PUT', "/api/shopping-list/{$itemId}", ['checked' => false]);
        self::assertFalse($uncheck['data']['checked']);
        $afterUncheck = $client->request('GET', '/api/shopping-list')['data'][0];
        self::assertNull($afterUncheck['checked_by_name']);
        self::assertNull($afterUncheck['checked_at']);

        $delete = $client->request('DELETE', "/api/shopping-list/{$itemId}");
        self::assertTrue($delete['ok']);
        self::assertCount(0, $client->request('GET', '/api/shopping-list')['data']);
    }

    public function testRejectsEmptyLabel(): void
    {
        $client = $this->loggedInClient();

        $res = $client->request('POST', '/api/shopping-list', ['label' => '   ']);
        self::assertFalse($res['ok']);
        self::assertSame(422, $res['status']);
    }

    public function testClearCheckedOnlyRemovesCheckedItems(): void
    {
        $client = $this->loggedInClient();

        $open = $client->request('POST', '/api/shopping-list', ['label' => 'Offen'])['data']['id'];
        $checked = $client->request('POST', '/api/shopping-list', ['label' => 'Erledigt'])['data']['id'];
        $client->request('PUT', "/api/shopping-list/{$checked}", ['checked' => true]);

        $clear = $client->request('POST', '/api/shopping-list/clear-checked');
        self::assertTrue($clear['ok']);
        self::assertSame(1, $clear['data']['removed']);

        $remaining = $client->request('GET', '/api/shopping-list')['data'];
        self::assertCount(1, $remaining);
        self::assertSame('Offen', $remaining[0]['label']);
    }

    public function testOpenItemsAreListedBeforeCheckedOnes(): void
    {
        $client = $this->loggedInClient();

        $first = $client->request('POST', '/api/shopping-list', ['label' => 'Zuerst angelegt'])['data']['id'];
        $client->request('POST', '/api/shopping-list', ['label' => 'Danach angelegt']);
        $client->request('PUT', "/api/shopping-list/{$first}", ['checked' => true]);

        $items = $client->request('GET', '/api/shopping-list')['data'];
        self::assertSame('Danach angelegt', $items[0]['label'], 'Offene Artikel müssen vor abgehakten stehen, unabhängig von der Anlage-Reihenfolge.');
        self::assertSame('Zuerst angelegt', $items[1]['label']);
    }

    public function testItemsAreScopedToOwnFamilyOnly(): void
    {
        $clientA = $this->loggedInClient('a@example.test');
        $clientA->request('POST', '/api/shopping-list', ['label' => 'Familie A Artikel']);

        $clientB = $this->loggedInClient('b@example.test');
        $itemsB = $clientB->request('GET', '/api/shopping-list');
        self::assertCount(0, $itemsB['data'], 'Familie B darf Artikel von Familie A nicht sehen.');
    }

    public function testCannotModifyForeignFamilyItem(): void
    {
        $clientA = $this->loggedInClient('a@example.test');
        $itemId = $clientA->request('POST', '/api/shopping-list', ['label' => 'Familie A Artikel'])['data']['id'];

        $clientB = $this->loggedInClient('b@example.test');
        $update = $clientB->request('PUT', "/api/shopping-list/{$itemId}", ['checked' => true]);
        self::assertFalse($update['ok']);
        self::assertSame(404, $update['status']);

        $delete = $clientB->request('DELETE', "/api/shopping-list/{$itemId}");
        self::assertFalse($delete['ok']);
        self::assertSame(404, $delete['status']);
    }
}
