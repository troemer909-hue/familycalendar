<?php

declare(strict_types=1);

namespace Familienkalender\Tests\Unit;

use Familienkalender\Core\TaskAssignmentDraw;
use PHPUnit\Framework\TestCase;

final class TaskAssignmentDrawTest extends TestCase
{
    public function testEveryTaskGetsAMemberWhenCapacityIsUnlimited(): void
    {
        $members = [
            ['family_member_id' => 1, 'max_tasks' => null],
            ['family_member_id' => 2, 'max_tasks' => null],
        ];
        $tasks = [
            ['id' => 10, 'last_assigned_member_id' => null],
            ['id' => 11, 'last_assigned_member_id' => null],
            ['id' => 12, 'last_assigned_member_id' => null],
        ];

        $result = TaskAssignmentDraw::draw($members, $tasks, 'random');

        self::assertCount(3, $result);
        foreach ($result as $memberId) {
            self::assertContains($memberId, [1, 2]);
        }
    }

    public function testFairModeExcludesLastAssigneeWhenAnotherMemberHasCapacity(): void
    {
        $members = [
            ['family_member_id' => 1, 'max_tasks' => null],
            ['family_member_id' => 2, 'max_tasks' => null],
        ];
        $tasks = [['id' => 10, 'last_assigned_member_id' => 1]];

        for ($i = 0; $i < 50; $i++) {
            $result = TaskAssignmentDraw::draw($members, $tasks, 'fair');
            self::assertSame(2, $result[10], 'Fairnessmodus muss die zuletzt zugewiesene Person ausschließen, solange eine andere verfügbar ist.');
        }
    }

    public function testRandomModeMayRepeatTheLastAssignee(): void
    {
        $members = [
            ['family_member_id' => 1, 'max_tasks' => null],
            ['family_member_id' => 2, 'max_tasks' => null],
        ];
        $tasks = [['id' => 10, 'last_assigned_member_id' => 1]];

        $sawRepeat = false;
        for ($i = 0; $i < 200; $i++) {
            $result = TaskAssignmentDraw::draw($members, $tasks, 'random');
            if ($result[10] === 1) {
                $sawRepeat = true;
                break;
            }
        }

        self::assertTrue($sawRepeat, 'Reiner Zufallsmodus darf dieselbe Person auch erneut ziehen.');
    }

    public function testFairModeWithOnlyOneMemberAlwaysAssignsThatMember(): void
    {
        $members = [['family_member_id' => 1, 'max_tasks' => null]];
        $tasks = [['id' => 10, 'last_assigned_member_id' => 1]];

        $result = TaskAssignmentDraw::draw($members, $tasks, 'fair');

        self::assertSame(1, $result[10], 'Bei nur einer Person im Pool greift der Ausschluss nicht - sie bleibt immer dran.');
    }

    public function testSingleMemberWithCapacityLeavesExcessTasksUnassigned(): void
    {
        // Kern der Maximalzahl-Funktion: auch mit nur einer Person im Pool
        // bleiben Aufgaben über der Obergrenze bewusst unzugeteilt statt
        // doch noch dieser einen Person aufgezwungen zu werden.
        $members = [['family_member_id' => 1, 'max_tasks' => 3]];
        $tasks = [
            ['id' => 10, 'last_assigned_member_id' => null],
            ['id' => 11, 'last_assigned_member_id' => null],
            ['id' => 12, 'last_assigned_member_id' => null],
            ['id' => 13, 'last_assigned_member_id' => null],
            ['id' => 14, 'last_assigned_member_id' => null],
        ];

        $result = TaskAssignmentDraw::draw($members, $tasks, 'random');

        $assigned = array_filter($result, static fn (?int $m): bool => $m !== null);
        $unassigned = array_filter($result, static fn (?int $m): bool => $m === null);

        self::assertCount(3, $assigned);
        self::assertCount(2, $unassigned);
    }

    public function testNoMemberEverExceedsItsMaxTasks(): void
    {
        $members = [
            ['family_member_id' => 1, 'max_tasks' => 2],
            ['family_member_id' => 2, 'max_tasks' => 1],
            ['family_member_id' => 3, 'max_tasks' => null],
        ];
        $tasks = array_map(static fn (int $i): array => ['id' => $i, 'last_assigned_member_id' => null], range(1, 20));

        for ($i = 0; $i < 50; $i++) {
            $result = TaskAssignmentDraw::draw($members, $tasks, 'random');
            $counts = array_count_values(array_filter($result, static fn (?int $m): bool => $m !== null));

            self::assertLessThanOrEqual(2, $counts[1] ?? 0);
            self::assertLessThanOrEqual(1, $counts[2] ?? 0);
        }
    }

    public function testFairnessExclusionYieldsToCapacityWhenNoOneElseIsAvailable(): void
    {
        // Beide Aufgaben waren zuletzt bei Person 1 - Fairness würde beide
        // lieber an Person 2 geben, aber deren Obergrenze (1) lässt das
        // nur für eine der beiden Aufgaben zu. Die zweite MUSS trotz
        // Fairness-Ausschluss wieder an Person 1 gehen, statt unzugeteilt
        // zu bleiben, obwohl Person 1 noch Kapazität hätte.
        $members = [
            ['family_member_id' => 1, 'max_tasks' => null],
            ['family_member_id' => 2, 'max_tasks' => 1],
        ];
        $tasks = [
            ['id' => 10, 'last_assigned_member_id' => 1],
            ['id' => 11, 'last_assigned_member_id' => 1],
        ];

        for ($i = 0; $i < 50; $i++) {
            $result = TaskAssignmentDraw::draw($members, $tasks, 'fair');

            self::assertNotContains(null, $result, 'Keine der beiden Aufgaben darf unzugeteilt bleiben - Person 1 hat noch Kapazität.');
            self::assertSame(1, count(array_filter($result, static fn (?int $m): bool => $m === 2)), 'Person 2 darf nie mehr als ihre Obergrenze (1) bekommen.');
            self::assertSame(1, count(array_filter($result, static fn (?int $m): bool => $m === 1)));
        }
    }

    public function testEmptyMemberPoolLeavesAllTasksUnassigned(): void
    {
        $result = TaskAssignmentDraw::draw([], [['id' => 10, 'last_assigned_member_id' => null]], 'random');

        self::assertSame([10 => null], $result);
    }

    public function testFixedOrderRotatesAfterLastAssignedMember(): void
    {
        $members = [
            ['family_member_id' => 3, 'max_tasks' => null, 'position' => 2],
            ['family_member_id' => 1, 'max_tasks' => null, 'position' => 0],
            ['family_member_id' => 2, 'max_tasks' => null, 'position' => 1],
        ];
        $tasks = [
            ['id' => 10, 'last_assigned_member_id' => null],
            ['id' => 11, 'last_assigned_member_id' => 1],
            ['id' => 12, 'last_assigned_member_id' => 2],
            ['id' => 13, 'last_assigned_member_id' => 3],
        ];
        $result = TaskAssignmentDraw::draw($members, $tasks, 'fixed_order');
        ksort($result);
        self::assertSame([10 => 1, 11 => 2, 12 => 3, 13 => 1], $result);
    }

    public function testFixedOrderSkipsFullMemberAndRestartsIfLastMemberWasRemoved(): void
    {
        $members = [
            ['family_member_id' => 2, 'max_tasks' => 0, 'position' => 0],
            ['family_member_id' => 3, 'max_tasks' => null, 'position' => 1],
        ];
        self::assertSame([10 => 3], TaskAssignmentDraw::draw($members, [['id' => 10, 'last_assigned_member_id' => null]], 'fixed_order'));
        $members[0]['max_tasks'] = null;
        self::assertSame([11 => 2], TaskAssignmentDraw::draw($members, [['id' => 11, 'last_assigned_member_id' => 99]], 'fixed_order'));
    }
}
