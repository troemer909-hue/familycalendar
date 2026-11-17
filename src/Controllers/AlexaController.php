<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\AlexaRequestVerifier;
use Familienkalender\Core\AlexaSignatureVerifier;
use Familienkalender\Core\Database;
use Familienkalender\Core\Env;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;
use Familienkalender\Core\TenantContext;

final class AlexaController
{
    private const DEFAULT_EVENT_MINUTES = 60;
    private const DAYPART_TIMES = [
        'TMO' => '09:00', 'TAF' => '15:00', 'TEV' => '19:00',
        'TNI' => '22:00', 'TNO' => '12:00', 'TMI' => '00:00',
    ];

    public function __construct(private readonly ?AlexaRequestVerifier $signatureVerifier = null) {}

    public function handle(Request $request): never
    {
        if (!(($this->signatureVerifier ?? new AlexaSignatureVerifier())->verify($request->rawBody, $request->headers))) {
            $this->respondError(401);
        }
        $payload = json_decode($request->rawBody, true);
        if (!is_array($payload)) {
            $this->respondError(400);
        }
        $expectedSkill = (string) Env::get('ALEXA_SKILL_ID', '');
        $skill = (string) ($payload['context']['System']['application']['applicationId'] ?? '');
        if ($expectedSkill === '' || !hash_equals($expectedSkill, $skill)) {
            $this->respondError(403);
        }

        $token = (string) ($payload['context']['System']['user']['accessToken'] ?? '');
        $stmt = Database::connection()->prepare(
            'SELECT fm.id AS member_id, fm.family_id, fm.role, al.user_id
             FROM alexa_links al JOIN family_members fm ON fm.user_id = al.user_id
             WHERE al.access_token_hash = :hash AND al.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $account = $token !== '' ? $stmt->fetch() : false;
        if (!$account) {
            Response::json(['version' => '1.0', 'response' => ['card' => ['type' => 'LinkAccount'], 'shouldEndSession' => true]]);
        }
        TenantContext::set((int) $account['user_id'], (int) $account['family_id'], (int) $account['member_id'], (string) $account['role']);

        $intentData = is_array($payload['request']['intent'] ?? null) ? $payload['request']['intent'] : [];
        $intentName = (string) ($intentData['name'] ?? '');
        $slots = is_array($intentData['slots'] ?? null) ? $intentData['slots'] : [];
        if (in_array($intentName, ['AddTaskIntent', 'CompleteTaskIntent', 'GetDueTasksIntent'], true)) {
            Response::json((new AlexaTaskIntentHandler())->handle($intentData));
        }
        if ($intentName === 'AddEventIntent') {
            $this->addEvent($intentData, $slots);
        }
        if ($intentName === 'DeleteEventIntent') {
            $this->deleteEvent($intentData, $slots);
        }
        if (in_array($name, ['AddShoppingItemIntent', 'CheckShoppingItemIntent', 'ReadShoppingListIntent', 'ClearCheckedShoppingItemsIntent'], true)) {
            $this->handleShoppingIntent($name, $slots);
        }

        $today = new \DateTimeImmutable('today');
        if ($intentName === 'GetTodayEventsIntent') {
            $from = $to = $today->format('Y-m-d');
            $period = 'today';
        } elseif ($intentName === 'GetTomorrowEventsIntent') {
            $from = $to = $today->modify('+1 day')->format('Y-m-d');
            $period = 'tomorrow';
        } elseif ($intentName === 'GetNextEventIntent') {
            $from = $today->format('Y-m-d');
            $to = $today->modify('+365 days')->format('Y-m-d');
            $period = 'next';
        } else {
            $this->respondError(400);
        }

        $events = $this->queryEvents($from, $to);
        if ($period === 'next') {
            $now = new \DateTimeImmutable();
            $events = array_values(array_filter($events, static fn(array $e): bool => new \DateTimeImmutable($e['ends_at']) >= $now));
            $events = array_slice($events, 0, 1);
        } else {
            $events = array_slice($events, 0, 5);
        }

        if (!$events) {
            $text = $period === 'today' ? 'Du hast heute nichts geplant.' : ($period === 'tomorrow' ? 'Du hast morgen nichts geplant.' : 'Es ist kein nächster Termin geplant.');
        } else {
            $labels = array_map(static fn(array $e): string => $e['title'] . ', ' . (!empty($e['all_day']) ? 'ganztägig' : (new \DateTimeImmutable($e['starts_at']))->format('H:i')), $events);
            if ($period === 'next') {
                $text = 'Dein nächster Termin ist ' . $labels[0] . '.';
            } else {
                $day = $period === 'today' ? 'heute' : 'morgen';
                $text = 'Du hast ' . $day . ' ' . count($events) . (count($events) === 1 ? ' Termin: ' : ' Termine: ') . implode('; ', $labels) . '.';
                $total = $this->countEvents($from, $to);
                if ($total > count($events)) {
                    $text .= ' Und ' . ($total - count($events)) . ' weitere.';
                }
            }
        }
        Response::json(['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $text], 'shouldEndSession' => true]]);
    }

    /** @param array<string,mixed> $slots */
    private function handleShoppingIntent(string $intent, array $slots): never
    {
        $controller = new ShoppingListController();
        if ($intent === 'AddShoppingItemIntent') {
            $item = trim((string) ($slots['item']['value'] ?? ''));
            if ($item === '') {
                Response::json(['version' => '1.0', 'response' => [
                    'outputSpeech' => ['type' => 'PlainText', 'text' => 'Was soll ich hinzufügen?'],
                    'directives' => [['type' => 'Dialog.ElicitSlot', 'slotToElicit' => 'item', 'updatedIntent' => ['name' => $intent, 'slots' => $slots]]],
                    'shouldEndSession' => false,
                ]]);
            }
            $this->capture(static fn() => $controller->create(new Request('POST', '/api/shopping-list', [], ['label' => $item])));
            $this->say('Ich habe ' . $item . ' zur Einkaufsliste hinzugefügt.');
        }

        if ($intent === 'CheckShoppingItemIntent') {
            $item = trim((string) ($slots['item']['value'] ?? ''));
            if ($item === '') $this->say('Welchen Artikel soll ich abhaken?');
            $items = $this->capture(static fn() => $controller->index(new Request('GET', '/api/shopping-list')))['data'];
            $open = array_values(array_filter($items, static fn(array $entry): bool => !$entry['checked']));
            $matches = $this->matchShoppingItems($item, $open);
            if (count($matches) === 1) {
                $request = new Request('PUT', '/api/shopping-list/' . $matches[0]['id'], [], ['checked' => true]);
                $request->params['id'] = (string) $matches[0]['id'];
                $this->capture(static fn() => $controller->update($request));
                $this->say($matches[0]['label'] . ' ist abgehakt.');
            }
            if (count($matches) > 1) $this->say('Meinst du ' . implode(' oder ', array_map(static fn(array $entry): string => '„' . $entry['label'] . '“', $matches)) . '?');
            $this->say('Ich finde „' . $item . '“ nicht auf der Einkaufsliste.');
        }

        if ($intent === 'ReadShoppingListIntent') {
            $items = $this->capture(static fn() => $controller->index(new Request('GET', '/api/shopping-list')))['data'];
            $open = array_values(array_filter($items, static fn(array $entry): bool => !$entry['checked']));
            if (!$open) $this->say('Deine Einkaufsliste ist leer.');
            $limit = 10;
            $text = 'Auf deiner Einkaufsliste stehen: ' . implode(', ', array_column(array_slice($open, 0, $limit), 'label'));
            if (count($open) > $limit) $text .= ' und ' . (count($open) - $limit) . ' weitere';
            $this->say($text . '.');
        }

        if ($intent === 'ClearCheckedShoppingItemsIntent') {
            $this->capture(static fn() => $controller->clearChecked(new Request('POST', '/api/shopping-list/clear-checked')));
            $this->say('Die abgehakten Artikel wurden von der Einkaufsliste entfernt.');
        }
        $this->respondError(400);
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,array<string,mixed>> */
    private function matchShoppingItems(string $spoken, array $items): array
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
        };
        $needle = $normalize($spoken);
        if ($needle === '') return [];
        $matches = [];
        foreach ($items as $entry) {
            $label = $normalize((string) $entry['label']);
            similar_text($needle, $label, $similarity);
            if (str_contains($label, $needle) || str_contains($needle, $label) || $similarity >= 70) $matches[] = $entry;
        }
        return $matches;
    }

    private function addEvent(array $intent, array $slots): never
    {
        $title = trim($this->slotValue($slots, 'title'));
        $dateValue = $this->slotValue($slots, 'date');
        $timeValue = $this->slotValue($slots, 'time');
        if ($title === '') {
            $this->elicitSlot($intent, $slots, 'title', 'Wie soll der Termin heißen?');
        }
        $date = $this->parseDate($dateValue);
        if ($date === null) {
            $this->elicitSlot($intent, $slots, 'date', 'Für welches Datum soll ich den Termin eintragen?');
        }
        $time = $this->parseTime($timeValue);
        if ($time === null) {
            $this->elicitSlot($intent, $slots, 'time', 'Um wie viel Uhr soll der Termin stattfinden?');
        }

        $start = new \DateTimeImmutable($date . ' ' . $time . ':00');
        $end = $start->modify('+' . self::DEFAULT_EVENT_MINUTES . ' minutes');
        if ($end <= $start) {
            $this->say('Das Enddatum muss nach dem Startdatum liegen. Bitte nenne Datum und Uhrzeit erneut.');
        }

        // Alexa-created events are private to the linked family member, matching
        // EventController's default scope. A one-hour duration is used because
        // the skill model does not collect an end time.
        $request = new Request('POST', '/api/events', [], [
            'title' => $title,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'scope' => 'personal',
            'participant_ids' => [TenantContext::familyMemberId()],
        ]);
        $result = $this->capture(static fn() => (new EventController())->create($request));
        if (($result['status'] ?? 500) >= 400) {
            $this->say($this->friendlyValidationMessage($result['data'] ?? []));
        }
        $this->say('Ich habe den Termin ' . $title . ' am ' . $this->dateLabel($date) . ' um ' . $time . ' Uhr eingetragen.');
    }

    private function deleteEvent(array $intent, array $slots): never
    {
        $title = trim($this->slotValue($slots, 'title'));
        if ($title === '') {
            $this->elicitSlot($intent, $slots, 'title', 'Welchen Termin soll ich löschen?');
        }
        $matches = $this->findDeleteCandidates($title);
        if ($matches === []) {
            $this->say('Ich konnte keinen Termin mit dem Namen ' . $title . ' finden.');
        }

        $date = $this->parseDate($this->slotValue($slots, 'date'));
        if ($date !== null) {
            $matches = array_values(array_filter($matches, static fn(array $e): bool => (new \DateTimeImmutable($e['starts_at']))->format('Y-m-d') === $date));
            if ($matches === []) {
                $this->say('Ich habe keinen Termin ' . $title . ' am ' . $this->dateLabel($date) . ' gefunden.');
            }
        }
        $time = $this->parseTime($this->slotValue($slots, 'time'));
        if ($time !== null) {
            $matches = array_values(array_filter($matches, static fn(array $e): bool => (new \DateTimeImmutable($e['starts_at']))->format('H:i') === $time));
            if ($matches === []) {
                $this->say('Ich habe keinen Termin ' . $title . ' um ' . $time . ' Uhr gefunden.');
            }
        }
        if (count($matches) > 1) {
            if ($date === null) {
                $this->elicitSlot($intent, $slots, 'date', $this->ambiguousDeletePrompt($title, $matches));
            }
            if ($time === null) {
                $this->elicitSlot($intent, $slots, 'time', $this->ambiguousDeletePrompt($title, $matches));
            }
            $this->say('Es gibt mehrere passende Termine. Bitte nenne Datum und Uhrzeit genauer.');
        }

        $deleteRequest = new Request('DELETE', '/api/events/' . $matches[0]['id'], [], [], null);
        $deleteRequest->params = ['id' => (string) $matches[0]['id']];
        $result = $this->capture(static fn() => (new EventController())->delete($deleteRequest));
        if (($result['status'] ?? 500) >= 400) {
            $this->say($this->friendlyValidationMessage($result['data'] ?? []));
        }
        $event = $matches[0];
        $this->say('Ich habe den Termin ' . $event['title'] . ' am ' . $this->dateLabel((new \DateTimeImmutable($event['starts_at']))->format('Y-m-d')) . ' gelöscht.');
    }

    /** @return array{status:int,data:mixed} */
    private function capture(callable $action): array
    {
        $captureWasEnabled = Response::isCaptureEnabled();
        Response::enableCapture();
        try {
            $action();
        } catch (ResponseCaptured $response) {
            return ['status' => $response->status, 'data' => $response->data];
        } finally {
            if (!$captureWasEnabled) {
                Response::disableCapture();
            }
        }
        return ['status' => 500, 'data' => []];
    }

    private function findDeleteCandidates(string $title): array
    {
        $pattern = '%' . strtr(mb_strtolower($title), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $sql = 'SELECT id, title, starts_at, ends_at FROM events
                WHERE family_id = :family_id AND LOWER(title) LIKE :pattern ESCAPE \'!\'';
        if (!TenantContext::isAdmin()) {
            $sql .= ' AND created_by = :created_by';
        }
        $sql .= ' ORDER BY starts_at ASC LIMIT 100';
        $stmt = Database::connection()->prepare($sql);
        $params = ['family_id' => TenantContext::familyId(), 'pattern' => $pattern];
        if (!TenantContext::isAdmin()) {
            $params['created_by'] = TenantContext::familyMemberId();
        }
        $stmt->execute($params);
        $events = $stmt->fetchAll();
        $needle = $this->normalizeTitle($title);
        return array_values(array_filter($events, fn(array $event): bool =>
            $needle !== '' && (str_contains($this->normalizeTitle($event['title']), $needle)
                || str_contains($needle, $this->normalizeTitle($event['title'])))
        ));
    }

    private function ambiguousDeletePrompt(string $title, array $matches): string
    {
        $options = array_map(fn(array $e): string => 'am ' . $this->dateLabel((new \DateTimeImmutable($e['starts_at']))->format('Y-m-d'))
            . ' um ' . (new \DateTimeImmutable($e['starts_at']))->format('H:i') . ' Uhr', array_slice($matches, 0, 3));
        $prompt = 'Ich habe mehrere Termine mit dem Namen ' . $title . ': ' . implode(', oder ', $options) . '. Welchen meinst du?';
        if (count($matches) > 3) {
            $prompt .= ' Es gibt noch weitere. Bitte nenne das Datum.';
        }
        return $prompt;
    }

    private function elicitSlot(array $intent, array $slots, string $slot, string $prompt): never
    {
        $updatedSlots = $slots;
        foreach (['title', 'date', 'time'] as $name) {
            if (!isset($updatedSlots[$name])) {
                $updatedSlots[$name] = ['name' => $name, 'confirmationStatus' => 'NONE'];
            }
        }
        Response::json([
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => $prompt],
                'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $prompt]],
                'directives' => [[
                    'type' => 'Dialog.ElicitSlot',
                    'slotToElicit' => $slot,
                    'updatedIntent' => [
                        'name' => (string) ($intent['name'] ?? ''),
                        'confirmationStatus' => 'NONE',
                        'slots' => $updatedSlots,
                    ],
                ]],
                'shouldEndSession' => false,
            ],
        ]);
    }

    private function say(string $text): never
    {
        Response::json(['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $text], 'shouldEndSession' => true]]);
    }

    private function friendlyValidationMessage(mixed $data): string
    {
        $error = is_array($data) ? (string) ($data['error'] ?? '') : '';
        if (str_contains($error, 'title, starts_at und ends_at')) return 'Der Termin konnte nicht angelegt werden. Bitte nenne Titel, Datum und Uhrzeit.';
        if (str_contains($error, 'Enddatum') || str_contains($error, 'Startdatum')) return 'Das Ende des Termins muss nach dem Beginn liegen.';
        if (str_contains($error, 'Teilnehmer')) return 'Ich konnte die Personen für den Termin nicht zuordnen.';
        return 'Der Termin konnte wegen eines Eingabefehlers nicht gespeichert werden. Bitte überprüfe die Angaben.';
    }

    private function slotValue(array $slots, string $name): string
    {
        $slot = $slots[$name] ?? [];
        if (!is_array($slot)) return '';
        $value = $slot['value'] ?? $slot['resolutions']['resolutionsPerAuthority'][0]['values'][0]['value']['name'] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) return null;
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year) ? $value : null;
    }

    private function parseTime(string $value): ?string
    {
        if (isset(self::DAYPART_TIMES[$value])) return self::DAYPART_TIMES[$value];
        if (preg_match('/^T(\d{2}):(\d{2})$/', $value, $m) !== 1) return null;
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        return $hour < 24 && $minute < 60 ? sprintf('%02d:%02d', $hour, $minute) : null;
    }

    private function normalizeTitle(string $title): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($title)));
    }

    private function dateLabel(string $date): string
    {
        $day = (int) (new \DateTimeImmutable($date))->format('N');
        $weekdays = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        return $weekdays[$day] . ', den ' . (new \DateTimeImmutable($date))->format('d.m.Y');
    }

    private function queryEvents(string $from, string $to): array
    {
        $captureWasEnabled = Response::isCaptureEnabled();
        Response::enableCapture();
        try {
            (new EventController())->index(new Request('GET', '/api/events', ['from' => $from, 'to' => $to], []));
        } catch (ResponseCaptured $response) {
            return $response->status === 200 && is_array($response->data) ? $response->data : [];
        } finally {
            if (!$captureWasEnabled) {
                Response::disableCapture();
            }
        }
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
        Response::json(['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => 'Die Anfrage konnte nicht verarbeitet werden.'], 'shouldEndSession' => true]], $status);
    }
}
