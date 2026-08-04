<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use Generator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `upsertBatch()` against a real in-memory SQLite database.
 *
 * Same change as {@see InsertBatchStreamTest}: rows used to be materialised in full
 * before the first statement, so peak memory tracked the size of the job. Now they are
 * buffered per column shape and flushed as each buffer fills.
 *
 * Upsert carries one extra concern the plain insert does not — the conflict clause has
 * to be applied to every batch, not just the first — so the tests below check that a
 * conflict resolved in one batch still resolves in the next.
 */
class UpsertBatchStreamTest extends TestCase
{
    private CDO $cdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }

        $this->cdo = (new SqliteDbCall())->connection();
        $this->cdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->cdo->exec(
            'CREATE TABLE inventory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT UNIQUE,
                name TEXT,
                qty INTEGER
            )'
        );
    }

    private function rows(int $n, int $qty = 1): Generator
    {
        for ($i = 0; $i < $n; $i++) {
            yield ['sku' => 'S' . $i, 'name' => 'Item ' . $i, 'qty' => $qty];
        }
    }

    private function rowCount(): int
    {
        return (int) $this->cdo->query('SELECT count(*) FROM inventory')->fetchColumn();
    }

    private function qty(string $sku): int
    {
        return (int) $this->cdo->query("SELECT qty FROM inventory WHERE sku='{$sku}'")->fetchColumn();
    }

    public function testAcceptsAGenerator(): void
    {
        $this->cdo->upsertBatch('inventory', $this->rows(250), ['sku'], ['qty' => ':new'], chunkSize: 100);

        $this->assertSame(250, $this->rowCount());
    }

    public function testAcceptsAPlainArray(): void
    {
        $this->cdo->upsertBatch('inventory', [
            ['sku' => 'A1', 'name' => 'Widget', 'qty' => 1],
        ], ['sku'], ['qty' => ':new']);

        $this->assertSame(1, $this->rowCount());
    }

    public function testAnEmptyIterableChangesNothing(): void
    {
        $this->cdo->upsertBatch('inventory', [], ['sku'], ['qty' => ':new']);

        $this->assertSame(0, $this->rowCount());
    }

    public function testTheLastPartialBatchIsFlushed(): void
    {
        // 7 rows at chunkSize 3 → two full batches plus a remainder of one.
        $this->cdo->upsertBatch('inventory', $this->rows(7), ['sku'], ['qty' => ':new'], chunkSize: 3);

        $this->assertSame(7, $this->rowCount());
    }

    /**
     * The conflict clause has to survive batching: a row updated in one statement must
     * still be updated when the same key arrives in a later one.
     */
    public function testConflictResolutionAppliesToEveryBatch(): void
    {
        $this->cdo->upsertBatch('inventory', $this->rows(10, qty: 1), ['sku'], ['qty' => ':new'], chunkSize: 3);
        $this->assertSame(10, $this->rowCount());

        // Same keys again, new value, batch boundaries deliberately different.
        $this->cdo->upsertBatch('inventory', $this->rows(10, qty: 42), ['sku'], ['qty' => ':new'], chunkSize: 4);

        $this->assertSame(10, $this->rowCount(), 'upsert must update, not duplicate');
        $this->assertSame(42, $this->qty('S0'), 'first row of the first batch');
        $this->assertSame(42, $this->qty('S9'), 'last row of the trailing partial batch');
    }

    /** Accumulating expressions must apply once per row, not once per batch. */
    public function testAccumulationIsPerRowAcrossBatches(): void
    {
        $this->cdo->upsertBatch('inventory', $this->rows(5, qty: 10), ['sku'], ['qty' => ':new'], chunkSize: 2);
        $this->cdo->upsertBatch(
            'inventory',
            $this->rows(5, qty: 5),
            ['sku'],
            ['qty' => ':current + :new'],
            chunkSize: 2,
        );

        $this->assertSame(15, $this->qty('S0'));
        $this->assertSame(15, $this->qty('S4'));
        $this->assertSame(5, $this->rowCount());
    }

    public function testRowsOfDifferentShapesAllArrive(): void
    {
        $mixed = (static function (): Generator {
            yield ['sku' => 'A1', 'name' => 'Widget'];
            yield ['sku' => 'B2', 'name' => 'Gadget', 'qty' => 5];
            yield ['sku' => 'C3', 'name' => 'Gizmo'];
        })();

        $this->cdo->upsertBatch('inventory', $mixed, ['sku'], ['qty' => ':new'], chunkSize: 2);

        $this->assertSame(3, $this->rowCount());
        $this->assertSame(5, $this->qty('B2'));
    }

    public function testARowWithoutNonNullColumnsIsRejected(): void
    {
        $this->expectException(CDOException::class);
        $this->cdo->upsertBatch('inventory', [['sku' => null, 'name' => null]], ['sku']);
    }

    /**
     * An empty conflict list is a programming error, and it is now caught before the
     * rows are touched — previously an empty collection returned first and hid it.
     */
    public function testMissingConflictColumnsIsRejectedEvenWithNoRows(): void
    {
        $this->expectException(CDOException::class);
        $this->expectExceptionMessage('conflictColumns is empty');

        $this->cdo->upsertBatch('inventory', [], []);
    }

    /** Peak memory must follow the batch, not the number of rows. */
    public function testPeakMemoryFollowsTheBatchNotTheRowCount(): void
    {
        $measure = function (int $rows): int {
            $this->cdo->exec('DELETE FROM inventory');
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();

            $this->cdo->upsertBatch('inventory', $this->rows($rows), ['sku'], ['qty' => ':new'], chunkSize: 200);

            return memory_get_peak_usage() - $before;
        };

        $small = $measure(1_000);
        $large = $measure(10_000);

        $this->assertSame(10_000, $this->rowCount());
        $this->assertLessThan(
            $small * 3,
            $large,
            sprintf('ten times the rows cost %d B instead of ~%d B', $large, $small),
        );
    }

    public function testEarlierBatchesRemainCommittedWhenALaterRowFails(): void
    {
        $withBadRow = (static function (): Generator {
            yield ['sku' => 'A1', 'name' => 'Widget'];
            yield ['sku' => 'B2', 'name' => 'Gadget'];
            yield ['sku' => null, 'name' => null];
        })();

        try {
            $this->cdo->upsertBatch('inventory', $withBadRow, ['sku'], ['qty' => ':new'], chunkSize: 2);
            $this->fail('the invalid row should have thrown');
        } catch (CDOException) {
            // expected
        }

        $this->assertSame(2, $this->rowCount(), 'the first full batch is already in the table');
    }
}
