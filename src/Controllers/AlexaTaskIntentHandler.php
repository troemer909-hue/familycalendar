<?php

declare(strict_types=1);

namespace Familienkalender\Controllers;

use Familienkalender\Core\Database;
use Familienkalender\Core\Request;
use Familienkalender\Core\Response;
use Familienkalender\Core\ResponseCaptured;
use Familienkalender\Core\TenantContext;

/** Runs only after AlexaController has verified the request and set tenant context. */
final class AlexaTaskIntentHandler
{
    public function handle(array $intent): array
    {
        $name = (string) ($intent['name'] ?? '');
        $slots = is_array($intent['slots'] ?? null) ? $intent['slots'] : [];
        return match ($name) {
            'AddTaskIntent' => $this->addTask($intent, $slots),
            'CompleteTaskIntent' => $this->completeTask($intent, $slots),
            'GetDueTasksIntent' => $this->getDueTasks(),
            default => $this->speech('Diese Aufgaben-Anfrage kenne ich nicht.'),
        };
    }

    private function addTask(array $intent, array $slots): array
    {
        $title = trim($this->slotValue($slots, 'title'));
        if ($title === '') return $this->elicitSlot($intent, $slots, 'title', 'Wie heißt die Aufgabe?');

        $lists = $this->lists();
        if ($lists === []) return $this->speech('Es gibt noch keine Aufgabenliste. Lege zuerst in der App eine Liste an.');
        $listName = trim($this->slotValue($slots, 'list_name'));
        if ($listName === '' && count($lists) === 1) {
            $list = $lists[0];
        } elseif ($listName === '' && count($lists) > 1) {
            return $this->elicitSlot($intent, $slots, 'list_name', $this->listPrompt($lists));
        } else {
            $matches = $this->matchListName($lists, $listName);
            if (count($matches) !== 1) {
                if ($matches === [] && $listName !== '') return $this->speech('Ich konnte keine Aufgabenliste mit dem Namen ' . $listName . ' finden.');
                return $this->elicitSlot($intent, $slots, 'list_name', $this->listPrompt($matches !== [] ? $matches : $lists));
            }
            $list = $matches[0];
        }

        $request = new Request('POST', '/api/task-lists/' . $list['id'] . '/tasks', [], ['title' => $title]);
        $request->params = ['id' => (string) $list['id']];
        $result = $this->capture(static fn() => (new TaskListController())->createTask($request));
        if ($result['status'] < 200 || $result['status'] >= 300) return $this->speech($this->friendlyTaskError($result['data']));
        return $this->speech('Ich habe die Aufgabe ' . $title . ' in die Liste ' . $list['name'] . ' eingetragen.');
    }

    private function completeTask(array $intent, array $slots): array
    {
        $title = trim($this->slotValue($slots, 'title'));
        if ($title === '') return $this->elicitSlot($intent, $slots, 'title', 'Welche Aufgabe hast du erledigt?');
        $matches = $this->findOpenTaskMatches($title);
        if ($matches === []) return $this->speech('Ich konnte keine offene Aufgabe mit dem Namen ' . $title . ' finden.');

        $listName = trim($this->slotValue($slots, 'list_name'));
        if ($listName !== '') {
            $matches = array_values(array_filter($matches, fn(array $task): bool => $this->namesMatch($task['list_name'], $listName)));
            if ($matches === []) return $this->speech('Ich habe keine offene Aufgabe ' . $title . ' in der Liste ' . $listName . ' gefunden.');
        }
        $dueDate = $this->slotValue($slots, 'due_date');
        if ($dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1) {
            return $this->elicitSlot($intent, $slots, 'due_date', 'Ich habe den Fälligkeitstag nicht erkannt. An welchem Tag ist die Aufgabe fällig?');
        }
        if ($dueDate !== '') {
            $matches = array_values(array_filter($matches, static fn(array $task): bool => $task['due_date'] === $dueDate));
            if ($matches === []) return $this->speech('Ich habe keine offene Aufgabe ' . $title . ' mit diesem Fälligkeitsdatum gefunden.');
        }

        if (count($matches) > 1) {
            $listNames = array_values(array_unique(array_column($matches, 'list_name')));
            if ($listName === '' && count($listNames) > 1) {
                return $this->elicitSlot($intent, $slots, 'list_name', $this->taskChoicePrompt($matches, 'In welcher Liste steht die Aufgabe?'));
            }
            $dates = array_values(array_unique(array_map(static fn(array $task): string => (string) ($task['due_date'] ?? ''), $matches)));
            if ($dueDate === '' && count($dates) > 1) {
                return $this->elicitSlot($intent, $slots, 'due_date', $this->taskChoicePrompt($matches, 'An welchem Fälligkeitstag steht die Aufgabe?'));
            }
            return $this->speech('Ich finde mehrere offene Aufgaben mit diesem Namen in derselben Liste und am selben Tag. Bitte gib der Aufgabe in der App einen eindeutigeren Namen.');
        }

        $task = $matches[0];
        $request = new Request('PUT', '/api/tasks/' . $task['id'], [], ['is_done' => true]);
        $request->params = ['id' => (string) $task['id']];
        $result = $this->capture(static fn() => (new TaskListController())->updateTask($request));
        if ($result['status'] < 200 || $result['status'] >= 300) return $this->speech($this->friendlyTaskError($result['data']));
        return $this->speech('Gut gemacht! Ich habe ' . $task['title'] . ' aus der Liste ' . $task['list_name'] . ' abgehakt.');
    }

    private function getDueTasks(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $request = new Request('GET', '/api/tasks/due', ['from' => $today, 'to' => $today], []);
        $result = $this->capture(static fn() => (new TaskListController())->dueTasks($request));
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($result['data'])) {
            return $this->speech('Die fälligen Aufgaben konnte ich gerade nicht laden.');
        }
        $tasks = $result['data'];
        if ($tasks === []) return $this->speech('Heute ist keine Aufgabe fällig.');
        $spoken = array_map(static fn(array $task): string => $task['title'] . ' in ' . $task['task_list_name'], array_slice($tasks, 0, 5));
        $text = count($tasks) === 1
            ? 'Heute ist eine Aufgabe fällig: ' . implode('; ', $spoken) . '.'
            : 'Heute sind ' . count($tasks) . ' Aufgaben fällig: ' . implode('; ', $spoken) . '.';
        if (count($tasks) > count($spoken)) $text .= ' Und ' . (count($tasks) - count($spoken)) . ' weitere.';
        return $this->speech($text);
    }

    private function findOpenTaskMatches(string $title): array
    {
        $pattern = '%' . strtr(mb_strtolower($title), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $stmt = Database::connection()->prepare(
            "SELECT t.id, t.title, t.due_date, tl.name AS list_name
             FROM tasks t JOIN task_lists tl ON tl.id = t.task_list_id
             WHERE tl.family_id = :family_id AND t.is_done = 0
               AND (LOWER(t.title) LIKE :pattern ESCAPE '!'
                    OR LOWER(:needle) LIKE CONCAT('%', LOWER(t.title), '%'))
             ORDER BY t.due_date ASC, tl.name ASC LIMIT 500"
        );
        $stmt->execute(['family_id' => TenantContext::familyId(), 'pattern' => $pattern, 'needle' => mb_strtolower($title)]);
        $needle = $this->normalize($title);
        return array_values(array_filter($stmt->fetchAll(), fn(array $task): bool =>
            $needle !== '' && (str_contains($this->normalize($task['title']), $needle)
                || str_contains($needle, $this->normalize($task['title'])))
        ));
    }

    private function lists(): array
    {
        $stmt = Database::connection()->prepare('SELECT id, name FROM task_lists WHERE family_id = :family_id ORDER BY created_at ASC, id ASC');
        $stmt->execute(['family_id' => TenantContext::familyId()]);
        return array_map(static fn(array $list): array => ['id' => (int) $list['id'], 'name' => $list['name']], $stmt->fetchAll());
    }

    private function matchListName(array $lists, string $name): array
    {
        $exact = array_values(array_filter($lists, fn(array $list): bool => $this->normalize($list['name']) === $this->normalize($name)));
        if ($exact !== []) return $exact;
        return array_values(array_filter($lists, fn(array $list): bool => $this->namesMatch($list['name'], $name)));
    }

    private function namesMatch(string $a, string $b): bool
    {
        $left = $this->normalize($a);
        $right = $this->normalize($b);
        return $left !== '' && $right !== '' && (str_contains($left, $right) || str_contains($right, $left));
    }

    private function taskChoicePrompt(array $tasks, string $lead): string
    {
        $choices = array_map(static function (array $task): string {
            $due = $task['due_date'] ? ' fällig am ' . (new \DateTimeImmutable($task['due_date']))->format('d.m.') : ' ohne Fälligkeitsdatum';
            return $task['title'] . ' in ' . $task['list_name'] . $due;
        }, array_slice($tasks, 0, 4));
        return $lead . ' Ich habe mehrere passende Aufgaben: ' . implode('; ', $choices) . '. Bitte wähle eine.';
    }

    private function listPrompt(array $lists): string
    {
        $names = array_map(static fn(array $list): string => '„' . $list['name'] . '“', $lists);
        return 'Du hast mehrere Aufgabenlisten: ' . implode(' oder ', $names) . '. In welche Liste soll ich die Aufgabe eintragen?';
    }

    private function elicitSlot(array $intent, array $slots, string $slotName, string $prompt): array
    {
        $slotNames = ($intent['name'] ?? '') === 'CompleteTaskIntent'
            ? ['title', 'list_name', 'due_date']
            : ['title', 'list_name'];
        foreach ($slotNames as $name) {
            if (!isset($slots[$name])) $slots[$name] = ['name' => $name, 'confirmationStatus' => 'NONE'];
        }
        return [
            'version' => '1.0',
            'response' => [
                'outputSpeech' => ['type' => 'PlainText', 'text' => $prompt],
                'reprompt' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $prompt]],
                'directives' => [[
                    'type' => 'Dialog.ElicitSlot',
                    'slotToElicit' => $slotName,
                    'updatedIntent' => [
                        'name' => (string) ($intent['name'] ?? ''),
                        'confirmationStatus' => 'NONE',
                        'slots' => $slots,
                    ],
                ]],
                'shouldEndSession' => false,
            ],
        ];
    }

    /** @return array{status:int,data:mixed} */
    private function capture(callable $action): array
    {
        Response::enableCapture();
        try {
            $action();
        } catch (ResponseCaptured $response) {
            return ['status' => $response->status, 'data' => $response->data];
        } finally {
            Response::disableCapture();
        }
        return ['status' => 500, 'data' => []];
    }

    private function slotValue(array $slots, string $name): string
    {
        $slot = $slots[$name] ?? [];
        if (!is_array($slot)) return '';
        $value = $slot['value'] ?? $slot['resolutions']['resolutionsPerAuthority'][0]['values'][0]['value']['name'] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }

    private function friendlyTaskError(mixed $data): string
    {
        $error = is_array($data) ? (string) ($data['error'] ?? '') : '';
        if (str_contains($error, 'nicht gefunden')) return 'Ich konnte die Aufgabe oder Aufgabenliste nicht mehr finden.';
        if (str_contains($error, 'title ist erforderlich')) return 'Bitte nenne einen Namen für die Aufgabe.';
        return 'Die Aufgabe konnte gerade nicht gespeichert werden. Bitte versuche es später erneut.';
    }

    private function speech(string $text): array
    {
        return ['version' => '1.0', 'response' => ['outputSpeech' => ['type' => 'PlainText', 'text' => $text], 'shouldEndSession' => true]];
    }
}
