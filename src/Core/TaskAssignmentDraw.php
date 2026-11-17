<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/**
 * Reine Lose-Logik für die zufällige Aufgaben-Zuteilung (siehe
 * docs/feature-ideas/aufgaben-zufallsverteilung.md) - bewusst ohne
 * Datenbankzugriff, damit sich die eigentliche Zufalls-/Fairness-/
 * Kapazitätslogik isoliert testen lässt. Die Datenbank-Seite (Aufgaben
 * und Pool-Mitglieder laden, Ergebnis speichern) übernimmt
 * TaskAssignmentRunner.
 */
final class TaskAssignmentDraw
{
    /**
     * Verlost für jede Aufgabe unabhängig ein Pool-Mitglied.
     *
     * Reihenfolge der Aufgaben wird vor der Ziehung gemischt, damit bei
     * knapper Gesamtkapazität nicht immer dieselben (z.B. zuerst in der
     * Liste stehenden) Aufgaben leer ausgehen - passt zum Los-Charakter
     * der Funktion (siehe "Offene Fragen" im Konzept-Dokument).
     *
     * Fairnessmodus schließt pro Aufgabe die zuletzt zugewiesene Person
     * aus, aber nur solange danach noch mindestens eine Person mit freier
     * Kapazität übrig bleibt - sonst würde eine volle Kapazität bei allen
     * ANDEREN Personen eine Aufgabe unnötig unzugeteilt lassen, obwohl die
     * zuletzt zugewiesene Person selbst noch Kapazität hätte. Die
     * Kapazitätsgrenze (max_tasks) ist dagegen eine harte Regel und wird
     * nie zugunsten der Fairness gebrochen.
     *
     * @param array<int, array{family_member_id:int, max_tasks:?int}> $members
     * @param array<int, array{id:int, last_assigned_member_id:?int}> $tasks
     * @param 'random'|'fair' $mode
     * @return array<int, ?int> Aufgaben-ID => gezogenes Mitglied, oder
     *                           null = diese Ziehung bleibt unzugeteilt
     *                           (Kapazität aller in Frage kommenden
     *                           Mitglieder ausgeschöpft).
     */
    public static function draw(array $members, array $tasks, string $mode): array
    {
        $memberIds = array_map(static fn (array $m): int => $m['family_member_id'], $members);
        $maxByMember = array_combine($memberIds, array_map(static fn (array $m): ?int => $m['max_tasks'], $members));
        $countByMember = array_fill_keys($memberIds, 0);
        $totalMembers = count($memberIds);

        $shuffledTasks = $tasks;
        shuffle($shuffledTasks);

        $result = [];
        foreach ($shuffledTasks as $task) {
            $withCapacity = array_values(array_filter($memberIds, static function (int $id) use ($maxByMember, $countByMember): bool {
                return $maxByMember[$id] === null || $countByMember[$id] < $maxByMember[$id];
            }));

            $candidates = $withCapacity;
            $lastAssigned = $task['last_assigned_member_id'];
            if ($mode === 'fair' && $totalMembers >= 2 && $lastAssigned !== null) {
                $withoutLast = array_values(array_diff($withCapacity, [$lastAssigned]));
                if (!empty($withoutLast)) {
                    $candidates = $withoutLast;
                }
            }

            if (empty($candidates)) {
                $result[$task['id']] = null;
                continue;
            }

            $chosen = $candidates[array_rand($candidates)];
            $result[$task['id']] = $chosen;
            $countByMember[$chosen]++;
        }

        return $result;
    }
}
