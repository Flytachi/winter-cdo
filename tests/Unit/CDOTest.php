<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for CDO::normalizeRow() — the dynamic batch-insert grouping
 * that keeps insertBatch()/upsertBatch() correct when rows have differing
 * column shapes (mirrors Hibernate @DynamicInsert) — and for the transaction()
 * rollback-safety behaviour.
 *
 * These methods touch no live connection state, so we exercise them on
 * instances built without the (connecting) constructor — no real database is
 * needed.
 */
class CDOTest extends TestCase
{
    /**
     * @param array $entities
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function group(array $entities): array
    {
        // Rebuilds what insertBatch()/upsertBatch() do row by row: normalize, then key
        // by the resulting column list. The grouping itself now lives in those methods
        // as buffers filled and flushed on the fly, so what stays testable here is the
        // invariant normalizeRow() owns — rows of the same shape must produce the same
        // key, whichever order their columns were written in.
        $cdo = (new \ReflectionClass(CDO::class))->newInstanceWithoutConstructor();
        $normalize = new ReflectionMethod(CDO::class, 'normalizeRow');

        $groups = [];
        foreach ($entities as $entity) {
            $row = $normalize->invoke($cdo, $entity);
            $groups[implode(',', array_keys($row))][] = $row;
        }

        return $groups;
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

    // ─── transaction() rollback safety ───────────────────────────────────

    public function testTransactionDoesNotMaskCallbackErrorWhenRollbackThrows(): void
    {
        // A CDO whose rollBack() itself throws — as can happen when the
        // transaction was already ended out-of-band (e.g. DDL implicit commit).
        // The callback's original exception must still be the one that surfaces.
        $cdo = new class extends CDO {
            public bool $rollbackAttempted = false;
            public function __construct()
            {
                // Intentionally skip parent::__construct — no DB connection is
                // opened; every PDO method transaction() uses is overridden below.
            }
            public function beginTransaction(): bool
            {
                return true;
            }
            public function commit(): bool
            {
                return true;
            }
            public function inTransaction(): bool
            {
                return true;
            }
            public function rollBack(): bool
            {
                $this->rollbackAttempted = true;
                throw new \PDOException('rollback failed');
            }
        };

        // transaction() writes to the private $logger on the rollback-failure path.
        (new ReflectionProperty(CDO::class, 'logger'))->setValue($cdo, new NullLogger());

        try {
            $cdo->transaction(function (): void {
                throw new RuntimeException('original error');
            });
            $this->fail('Expected the callback exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('original error', $e->getMessage());
        }

        $this->assertTrue($cdo->rollbackAttempted, 'rollBack() should have been attempted');
    }
}
