<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for CDO::groupRowsBySignature() — the dynamic batch-insert grouping
 * that keeps insertGroup()/upsertGroup() correct when rows have differing
 * column shapes (mirrors Hibernate @DynamicInsert).
 *
 * groupRowsBySignature() touches no connection state, so we invoke it via
 * reflection on an instance built without the (connecting) constructor — no
 * real database is needed.
 */
class CDOTest extends TestCase
{
    /**
     * @param array $entities
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function group(array $entities): array
    {
        $cdo = (new \ReflectionClass(CDO::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CDO::class, 'groupRowsBySignature');
        return $method->invoke($cdo, $entities);
    }

    public function testHomogeneousRowsFormSingleGroup(): void
    {
        $groups = $this->group([
            ['name' => 'A', 'email' => 'a@x'],
            ['name' => 'B', 'email' => 'b@x'],
        ]);

        $this->assertCount(1, $groups);
        $rows = array_values($groups)[0];
        $this->assertCount(2, $rows);
        // Every row in a group shares the same column set — no mismatch possible.
        $this->assertSame(array_keys($rows[0]), array_keys($rows[1]));
    }

    public function testHeterogeneousNullPatternsSplitIntoGroups(): void
    {
        // This is the regression case: row 1 keeps email, row 2 drops it (NULL).
        $groups = $this->group([
            ['id' => null, 'name' => 'A', 'email' => 'a@x'], // -> name,email
            ['id' => null, 'name' => 'B', 'email' => null],  // -> name
        ]);

        $this->assertCount(2, $groups);
        $this->assertArrayHasKey('email,name', $groups);
        $this->assertArrayHasKey('name', $groups);

        // Within every group, all rows expose an identical key set.
        foreach ($groups as $rows) {
            $reference = array_keys($rows[0]);
            foreach ($rows as $row) {
                $this->assertSame($reference, array_keys($row));
            }
        }
    }

    public function testNullValuesAreStripped(): void
    {
        $groups = $this->group([
            ['id' => null, 'name' => 'A', 'deleted_at' => null],
        ]);

        $rows = array_values($groups)[0];
        $this->assertSame(['name' => 'A'], $rows[0]);
    }

    public function testSameColumnsDifferentOrderGroupTogether(): void
    {
        // ksort canonicalisation: {name,email} and {email,name} are one group.
        $groups = $this->group([
            ['name' => 'A', 'email' => 'a@x'],
            ['email' => 'b@x', 'name' => 'B'],
        ]);

        $this->assertCount(1, $groups);
        $rows = array_values($groups)[0];
        // Canonical (sorted) column order applied to every row.
        $this->assertSame(['email', 'name'], array_keys($rows[0]));
        $this->assertSame(['email', 'name'], array_keys($rows[1]));
    }

    public function testObjectsAreConvertedToArrays(): void
    {
        $a = new \stdClass();
        $a->name = 'A';
        $a->email = 'a@x';

        $groups = $this->group([$a]);

        $rows = array_values($groups)[0];
        $this->assertSame(['email' => 'a@x', 'name' => 'A'], $rows[0]);
    }

    public function testRowWithOnlyNullColumnsThrows(): void
    {
        $this->expectException(CDOException::class);
        $this->group([
            ['id' => null, 'deleted_at' => null],
        ]);
    }
}
