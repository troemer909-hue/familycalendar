<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Integration;

use Familienkalender\Controllers\AlexaController;
use Familienkalender\Core\AlexaRequestVerifier;
use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;
use Familienkalender\Tests\Support\ApiServer;
use Familienkalender\Tests\Support\IntegrationDb;
use PDO;
use PHPUnit\Framework\TestCase;

final class AlexaShoppingIntentsTest extends TestCase
{
    private PDO $db;
    private int $familyId;
    private int $memberId;
    private string $accessToken = 'alexa-shopping-test-token';

    protected function setUp(): void
    {
        IntegrationDb::skipUnlessConfigured();
        $this->db = IntegrationDb::freshDatabase();

        $anonymous = new \ApiClient(ApiServer::baseUrl(), null);
        $registered = $anonymous->request('POST', '/api/auth/register', [
            'family_name' => 'Alexa-Einkaufstest',
            'admin_name' => 'Admin',
            'email' => 'alexa-shopping-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'sicheres-passwort',
        ]);
        self::assertTrue($registered['ok'], json_encode($registered));
        $client = new \ApiClient(ApiServer::baseUrl(), $registered['data']['token']);
        $members = $client->request('GET', '/api/members');
        self::assertTrue($members['ok'], json_encode($members));
        $this->memberId = (int) $members['data'][0]['id'];
        $lookup = $this->db->prepare('SELECT family_id FROM family_members WHERE id = :id');
        $lookup->execute(['id' => $this->memberId]);
        $this->familyId = (int) $lookup->fetchColumn();

        $this->db->prepare(
            'INSERT INTO alexa_links (user_id, access_token_hash, refresh_token_hash, expires_at, refresh_expires_at)
             SELECT user_id, :access_hash, :refresh_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 90 DAY)
             FROM family_members WHERE id = :member_id'
        )->execute([
            'access_hash' => hash('sha256', $this->accessToken),
            'refresh_hash' => hash('sha256', 'alexa-shopping-test-refresh'),
            'member_id' => $this->memberId,
        ]);
        putenv('ALEXA_SKILL_ID=test-skill');
    }

    public function testAddShoppingItemIntentCreatesOpenItem(): void
    {
        $response = $this->invoke('AddShoppingItemIntent', ['item' => ['name' => 'item', 'value' => 'Milch']]);

        self::assertStringContainsString('Milch', $response->data['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT checked FROM shopping_list_items WHERE family_id = :family_id AND label = :label');
        $stmt->execute(['family_id' => $this->familyId, 'label' => 'Milch']);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testAddShoppingItemIntentWithoutItemElicitsSlot(): void
    {
        $response = $this->invoke('AddShoppingItemIntent', []);

        self::assertSame('Dialog.ElicitSlot', $response->data['response']['directives'][0]['type']);
        self::assertSame('item', $response->data['response']['directives'][0]['slotToElicit']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM shopping_list_items')->fetchColumn());
    }

    public function testCheckShoppingItemIntentChecksUniqueMatch(): void
    {
        $this->insertItem('Milch');
        $response = $this->invoke('CheckShoppingItemIntent', ['item' => ['name' => 'item', 'value' => 'Milch']]);

        self::assertStringContainsString('abgehakt', $response->data['response']['outputSpeech']['text']);
        $stmt = $this->db->prepare('SELECT checked FROM shopping_list_items WHERE family_id = :family_id AND label = :label');
        $stmt->execute(['family_id' => $this->familyId, 'label' => 'Milch']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testCheckShoppingItemIntentAsksWhenSeveralItemsMatch(): void
    {
        $this->insertItem('Milch');
        $this->insertItem('Milchschokolade');
        $response = $this->invoke('CheckShoppingItemIntent', ['item' => ['name' => 'item', 'value' => 'Milch']]);

        self::assertStringContainsString('Meinst du', $response->data['response']['outputSpeech']['text']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM shopping_list_items WHERE checked = 1')->fetchColumn());
    }

    public function testCheckShoppingItemIntentReportsUnknownItem(): void
    {
        $this->insertItem('Milch');
        $response = $this->invoke('CheckShoppingItemIntent', ['item' => ['name' => 'item', 'value' => 'Käse']]);

        self::assertStringContainsString('nicht auf der Einkaufsliste', $response->data['response']['outputSpeech']['text']);
    }

    public function testReadShoppingListIntentHandlesEmptyAndListsOpenItems(): void
    {
        $empty = $this->invoke('ReadShoppingListIntent', []);
        self::assertStringContainsString('leer', $empty->data['response']['outputSpeech']['text']);

        $this->insertItem('Milch');
        $this->insertItem('Brot');
        $this->insertItem('Käse', checked: true);
        $response = $this->invoke('ReadShoppingListIntent', []);
        $text = $response->data['response']['outputSpeech']['text'];
        self::assertStringContainsString('Milch', $text);
        self::assertStringContainsString('Brot', $text);
        self::assertStringNotContainsString('Käse', $text);
    }

    public function testClearCheckedShoppingItemsIntentRemovesOnlyCheckedItems(): void
    {
        $this->insertItem('Milch');
        $this->insertItem('Käse', checked: true);

        $response = $this->invoke('ClearCheckedShoppingItemsIntent', []);
        self::assertStringContainsString('entfernt', $response->data['response']['outputSpeech']['text']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM shopping_list_items')->fetchColumn());
        self::assertSame(
            'Milch',
            $this->db->query('SELECT label FROM shopping_list_items')->fetchColumn()
        );
    }

    private function insertItem(string $label, bool $checked = false): void
    {
        $this->db->prepare(
            'INSERT INTO shopping_list_items (family_id, added_by, label, checked)
             VALUES (:family_id, :added_by, :label, :checked)'
        )->execute([
            'family_id' => $this->familyId,
            'added_by' => $this->memberId,
            'label' => $label,
            'checked' => $checked ? 1 : 0,
        ]);
    }

    private function invoke(string $intentName, array $slots): ResponseCaptured
    {
        $raw = json_encode([
            'version' => '1.0',
            'context' => [
                'System' => [
                    'application' => ['applicationId' => 'test-skill'],
                    'user' => ['accessToken' => $this->accessToken],
                ],
            ],
            'request' => [
                'type' => 'IntentRequest',
                'timestamp' => gmdate(DATE_ATOM),
                'intent' => ['name' => $intentName, 'slots' => $slots],
            ],
        ], JSON_THROW_ON_ERROR);
        $request = new Request('POST', '/api/alexa/webhook', [], [], null, $raw, ['signature' => 'test', 'signaturecertchainurl' => 'test']);
        $verifier = new class implements AlexaRequestVerifier {
            public function verify(string $rawBody, array $headers, ?int $now = null): bool { return true; }
        };

        Response::enableCapture();
        try {
            (new AlexaController($verifier))->handle($request);
        } catch (ResponseCaptured $response) {
            return $response;
        } finally {
            Response::disableCapture();
        }
        self::fail('Alexa controller did not return a response.');
    }
}
