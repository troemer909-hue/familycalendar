<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PHPUnit\Framework\TestCase;

/**
 * Deckt den Vorrat-Tracker ab (siehe
 * docs/feature-ideas/vorrat-nachbestellen.md): "wird knapp" erzeugt
 * automatisch einen Einkaufslisten-Eintrag, dessen Abhaken wiederum
 * low_stock in der Vorratsliste zurücksetzt - die Rückkopplung
 * zwischen HouseholdStockController und ShoppingListController ist der
 * interessante Teil dieses Features.
 */
final class HouseholdStockTest extends TestCase
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

    public function testFlagLowCreatesShoppingListEntryAndCheckingItResetsLowStock(): void
    {
        $client = $this->loggedInClient();

        $create = $client->request('POST', '/api/household-stock', ['label' => 'Toilettenpapier']);
        self::assertTrue($create['ok'], json_encode($create));
        $stockId = $create['data']['id'];

        self::assertCount(0, $client->request('GET', '/api/shopping-list')['data']);

        $flag = $client->request('POST', "/api/household-stock/{$stockId}/flag-low");
        self::assertTrue($flag['ok']);
        self::assertTrue($flag['data']['low_stock']);

        $stock = $client->request('GET', '/api/household-stock')['data'];
        self::assertTrue($stock[0]['low_stock']);
        self::assertNotNull($stock[0]['flagged_at']);

        $shoppingItems = $client->request('GET', '/api/shopping-list')['data'];
        self::assertCount(1, $shoppingItems);
        self::assertSame('Toilettenpapier', $shoppingItems[0]['label']);
        $shoppingItemId = $shoppingItems[0]['id'];

        // Abhaken in der Einkaufsliste muss low_stock automatisch zurücksetzen.
        $client->request('PUT', "/api/shopping-list/{$shoppingItemId}", ['checked' => true]);

        $stockAfterCheck = $client->request('GET', '/api/household-stock')['data'];
        self::assertFalse($stockAfterCheck[0]['low_stock'], 'Abhaken des erzeugten Einkaufslisten-Eintrags muss low_stock zurücksetzen.');
    }

    public function testFlaggingAnAlreadyLowItemDoesNotCreateASecondShoppingListEntry(): void
    {
        $client = $this->loggedInClient();

        $stockId = $client->request('POST', '/api/household-stock', ['label' => 'Spülmaschinentabs'])['data']['id'];
        $client->request('POST', "/api/household-stock/{$stockId}/flag-low");
        $second = $client->request('POST', "/api/household-stock/{$stockId}/flag-low");
        self::assertTrue($second['ok']);

        self::assertCount(1, $client->request('GET', '/api/shopping-list')['data'], 'Mehrfaches "wird knapp" darf keinen zweiten Einkaufslisten-Eintrag erzeugen.');
    }

    public function testDuplicateLabelIsRejected(): void
    {
        $client = $this->loggedInClient();

        $client->request('POST', '/api/household-stock', ['label' => 'Windeln']);
        $duplicate = $client->request('POST', '/api/household-stock', ['label' => 'Windeln']);

        self::assertFalse($duplicate['ok']);
        self::assertSame(422, $duplicate['status']);
    }

    public function testDeletingStockItemKeepsAlreadyCreatedShoppingListEntry(): void
    {
        $client = $this->loggedInClient();

        $stockId = $client->request('POST', '/api/household-stock', ['label' => 'Müllbeutel'])['data']['id'];
        $client->request('POST', "/api/household-stock/{$stockId}/flag-low");

        $delete = $client->request('DELETE', "/api/household-stock/{$stockId}");
        self::assertTrue($delete['ok']);

        $shoppingItems = $client->request('GET', '/api/shopping-list')['data'];
        self::assertCount(1, $shoppingItems, 'Der bereits erzeugte Einkaufslisten-Eintrag darf durch das Löschen des Vorratsartikels nicht verschwinden.');
    }

    public function testStockItemsAreScopedToOwnFamilyOnly(): void
    {
        $clientA = $this->loggedInClient('a@example.test');
        $clientA->request('POST', '/api/household-stock', ['label' => 'Familie A Artikel']);

        $clientB = $this->loggedInClient('b@example.test');
        self::assertCount(0, $clientB->request('GET', '/api/household-stock')['data']);
    }

    public function testCannotFlagForeignFamilyItem(): void
    {
        $clientA = $this->loggedInClient('a@example.test');
        $stockId = $clientA->request('POST', '/api/household-stock', ['label' => 'Familie A Artikel'])['data']['id'];

        $clientB = $this->loggedInClient('b@example.test');
        $res = $clientB->request('POST', "/api/household-stock/{$stockId}/flag-low");
        self::assertFalse($res['ok']);
        self::assertSame(404, $res['status']);
    }
}
