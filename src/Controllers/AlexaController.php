<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\AlexaSignatureVerifier;
use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;
use Familienkalender\Core\TenantContext;

final class AlexaController
{
    public function __construct(private readonly ?AlexaSignatureVerifier $signatureVerifier = null) {}

    public function handle(Request $request): never
    {
        $raw = file_get_contents('php://input') ?: '';
        $headers = ['signature' => (string) ($_SERVER['HTTP_SIGNATURE'] ?? ''), 'signaturecertchainurl' => (string) ($_SERVER['HTTP_SIGNATURECERTCHAINURL'] ?? '')];
        if (!($this->signatureVerifier ?? new AlexaSignatureVerifier())->verify($raw, $headers)) $this->respondError(401);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) $this->respondError(400);
        $expectedSkill = (string) Env::get('ALEXA_SKILL_ID', '');
        $skill = (string) ($payload['context']['System']['application']['applicationId'] ?? '');
        if ($expectedSkill === '' || !hash_equals($expectedSkill, $skill)) $this->respondError(403);

        $token = (string) ($payload['context']['System']['user']['accessToken'] ?? '');
        $stmt = Database::connection()->prepare('SELECT fm.id AS member_id, fm.family_id, fm.role, al.user_id FROM alexa_links al JOIN family_members fm ON fm.user_id = al.user_id WHERE al.access_token_hash = :hash AND al.expires_at > NOW() LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $account = $token !== '' ? $stmt->fetch() : false;
        if (!$account) Response::json(['version' => '1.0', 'response' => ['card' => ['type' => 'LinkAccount'], 'shouldEndSession' => true]]);
        TenantContext::set((int) $account['user_id'], (int) $account['family_id'], (int) $account['member_id'], (string) $account['role']);

        $intent = (string) ($payload['request']['intent']['name'] ?? '');
        $today = new \DateTimeImmutable('today');
        if ($intent === 'GetTodayEventsIntent') { $from = $to = $today->format('Y-m-d'); $period = 'today'; }
        elseif ($intent === 'GetTomorrowEventsIntent') { $from = $to = $today->modify('+1 day')->format('Y-m-d'); $period = 'tomorrow'; }
        elseif ($intent === 'GetNextEventIntent') { $from = $today->format('Y-m-d'); $to = $today->modify('+365 days')->format('Y-m-d'); $period = 'next'; }
        else $this->respondError(400);

        $events = $this->queryEvents($from, $to);
        if ($period === 'next') {
            $now = new \DateTimeImmutable();
            $events = array_values(array_filter($events, static fn(array $e): bool => new \DateTimeImmutable($e['ends_at']) >= $now));
            $events = array_slice($events, 0, 1);
        } else $events = array_slice($events, 0, 5);

        if (!$events) $text = $period === 'today' ? 'Du hast heute nichts geplant.' : ($period === 'tomorrow' ? 'Du hast morgen nichts geplant.' : 'Es ist kein nächster Termin geplant.');
        else {
            $labels = array_map(static fn(array $e): string => $e['title'] . ', ' . (!empty($e['all_day']) ? 'ganztägig' : (new \DateTimeImmutable($e['starts_at']))->format('H:i')), $events);
            if ($period === 'next') $text = 'Dein nächster Termin ist ' . $labels[0] . '.';
            else {
                $day = $period === 'today' ? 'heute' : 'morgen';
                $text = 'Du hast ' . $day . ' ' . count($events) . (count($events) === 1 ? ' Termin: ' : ' Termine: ') . implode('; ', $labels) . '.';
                $total = $this->countEvents($from, $to);
                if ($total > count($events)) $text .= ' Und ' . ($total - count($events)) . ' weitere.';
            }
        }
        Response::json(['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $text], 'shouldEndSession' => true]]);
    }

    private function queryEvents(string $from, string $to): array
    {
        Response::enableCapture();
        try { (new EventController())->index(new Request('GET', '/api/events', ['from' => $from, 'to' => $to], [])); }
        catch (ResponseCaptured $response) { return $response->status === 200 && is_array($response->data) ? $response->data : []; }
        finally { Response::disableCapture(); }
        return [];
    }

    private function countEvents(string $from, string $to): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM events WHERE family_id = :family_id AND starts_at < :to AND ends_at >= :from');
        $stmt->execute(['family_id' => TenantContext::familyId(), 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59']);
        return (int) $stmt->fetchColumn();
    }

    private function respondError(int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['version' => '1.0', 'response' => ['shouldEndSession' => true]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
