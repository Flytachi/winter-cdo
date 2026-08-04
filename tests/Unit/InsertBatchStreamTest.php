<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use Generator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * `insertBatch()` against a real in-memory SQLite database.
 *
 * The method used to build every row up front and only then talk to the server, so
 * peak memory grew with the size of the job rather than with the batch: measured on a
 * live application, 500 000 entities cost 440 MiB — of which 352 MiB was the rows
 * converted to arrays, not the application's own objects. A worker with the default
 * 128 MiB limit died before the first INSERT was sent.
 *
 * Now rows are buffered per column shape and flushed as each buffer fills, so a
 * generator of a million rows costs the same as one of a thousand. These tests prove
 * both halves: the results are identical to the eager version, and the memory really
 * does stay bounded.
 */
class InsertBatchStreamTest extends TestCase
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
                qty INTEGER,
                price REAL
            )'
        );
    }

    private function rows(int $n, int $from = 0): Generator
    {
        for ($i = $from; $i < $from + $n; $i++) {
            yield ['sku' => 'S' . $i, 'name' => 'Item ' . $i, 'qty' => $i];
        }
    }

    private function rowCount(): int
    {
        return (int) $this->cdo->query('SELECT count(*) FROM inventory')->fetchColumn();
    }

    /**
     * Replaces `$this->cdo` with one that records the SQL it issues.
     *
     * Row counts cannot distinguish "one multi-row INSERT" from "several" — both fill
     * the table identically — so the statements have to be observed directly. CDO logs
     * each batch it sends, which makes a recording logger the cheapest way in.
     */
    private function recordingCdo(): RecordingLogger
    {
        $logger = new RecordingLogger();
        $config = new SqliteDbCall();
        $config->setLogger($logger);

        $this->cdo = $config->connection();
        $this->cdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->cdo->exec(
            'CREATE TABLE inventory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT UNIQUE,
                name TEXT,
                qty INTEGER,
                price REAL
            )'
        );
        $logger->reset();

        return $logger;
    }

    // ── Every shape of iterable ────────────────────────────────────────────────

    public function testAcceptsAPlainArray(): void
    {
        $n = $this->cdo->insertBatch('inventory', [
            ['sku' => 'A1', 'name' => 'Widget'],
            ['sku' => 'B2', 'name' => 'Gadget'],
        ]);

        $this->assertSame(2, $n);
        $this->assertSame(2, $this->rowCount());
    }

    public function testAcceptsAGenerator(): void
    {
        $n = $this->cdo->insertBatch('inventory', $this->rows(250), chunkSize: 100);

        $this->assertSame(250, $n);
        $this->assertSame(250, $this->rowCount());
    }

    public function testAcceptsAnyTraversable(): void
    {
        $n = $this->cdo->insertBatch('inventory', new \ArrayIterator([
            ['sku' => 'A1', 'name' => 'Widget'],
        ]));

        $this->assertSame(1, $n);
    }

    public function testAcceptsObjects(): void
    {
        $a = new \stdClass();
        $a->sku = 'A1';
        $a->name = 'Widget';
        $b = new \stdClass();
        $b->sku = 'B2';
        $b->name = 'Gadget';

        $this->assertSame(2, $this->cdo->insertBatch('inventory', [$a, $b]));
    }

    public function testAnEmptyIterableInsertsNothing(): void
    {
        $empty = (static function (): Generator {
            if (false) {
                yield [];
            }
        })();

        $this->assertSame(0, $this->cdo->insertBatch('inventory', $empty));
        $this->assertSame(0, $this->cdo->insertBatch('inventory', []));
        $this->assertSame(0, $this->rowCount());
    }

    // ── Chunking ───────────────────────────────────────────────────────────────

    /** Every row must arrive whichever way the batch boundaries fall. */
    public function testRowsSurviveEveryChunkBoundary(): void
    {
        foreach ([1, 2, 7, 250, 1000] as $chunkSize) {
            $this->cdo->exec('DELETE FROM inventory');

            $n = $this->cdo->insertBatch('inventory', $this->rows(250), chunkSize: $chunkSize);

            $this->assertSame(250, $n, "chunkSize={$chunkSize} lost rows in the return value");
            $this->assertSame(250, $this->rowCount(), "chunkSize={$chunkSize} lost rows in the table");
        }
    }

    /** A batch of zero is a statement per row — the natural reading, so no clamping. */
    public function testANonPositiveChunkSizeSendsOneRowPerStatement(): void
    {
        $log = $this->recordingCdo();

        $n = $this->cdo->insertBatch('inventory', $this->rows(5), chunkSize: 0);

        $this->assertSame(5, $n);
        $this->assertSame(5, $this->rowCount());
        $this->assertCount(5, $log->inserts(), 'five rows, five statements');
    }

    public function testTheLastPartialBatchIsFlushed(): void
    {
        // 7 rows at chunkSize 3 → two full batches plus a remainder of one.
        $this->assertSame(7, $this->cdo->insertBatch('inventory', $this->rows(7), chunkSize: 3));
        $this->assertSame(7, $this->rowCount());
    }

    // ── Heterogeneous rows ─────────────────────────────────────────────────────

    /**
     * Rows differing in which columns are null cannot share one VALUES list, so each
     * shape gets its own buffer — and each buffer its own remainder to flush.
     */
    public function testRowsOfDifferentShapesAllArrive(): void
    {
        $mixed = (static function (): Generator {
            yield ['sku' => 'A1', 'name' => 'Widget'];
            yield ['sku' => 'B2', 'name' => 'Gadget', 'qty' => 5, 'price' => 1.2];
            yield ['sku' => 'C3', 'name' => 'Gizmo'];
            yield ['sku' => 'D4', 'name' => 'Doohickey', 'qty' => 7, 'price' => 3.4];
        })();

        $this->assertSame(4, $this->cdo->insertBatch('inventory', $mixed, chunkSize: 2));
        $this->assertSame(4, $this->rowCount());
        $this->assertSame(
            5,
            (int) $this->cdo->query("SELECT qty FROM inventory WHERE sku='B2'")->fetchColumn(),
        );
        $this->assertNull(
            $this->cdo->query("SELECT qty FROM inventory WHERE sku='A1'")->fetchColumn() ?: null,
        );
    }

    /**
     * Two rows carrying the same columns in a different order are the same shape and
     * must combine into **one** statement. Counting rows cannot show this — both end
     * up in the table either way — so the statements themselves are counted.
     */
    public function testColumnOrderDoesNotSplitAShape(): void
    {
        $log = $this->recordingCdo();

        $n = $this->cdo->insertBatch('inventory', [
            ['sku' => 'A1', 'name' => 'Widget'],
            ['name' => 'Gadget', 'sku' => 'B2'],
        ], chunkSize: 1000);

        $this->assertSame(2, $n);
        $this->assertSame(2, $this->rowCount());
        $this->assertCount(1, $log->inserts(), 'one shape must produce one multi-row INSERT');
    }

    /** …and genuinely different shapes must not be forced into one statement. */
    public function testDifferentShapesGetTheirOwnStatement(): void
    {
        $log = $this->recordingCdo();

        $this->cdo->insertBatch('inventory', [
            ['sku' => 'A1', 'name' => 'Widget'],
            ['sku' => 'B2', 'name' => 'Gadget', 'qty' => 5],
        ], chunkSize: 1000);

        $this->assertCount(2, $log->inserts(), 'two shapes, two statements');
    }

    public function testARowWithoutNonNullColumnsIsRejected(): void
    {
        $this->expectException(CDOException::class);
        $this->cdo->insertBatch('inventory', [['sku' => null, 'name' => null]]);
    }

    // ── The point of the change ────────────────────────────────────────────────

    /**
     * Peak memory must follow the batch, not the job.
     *
     * The generator is consumed lazily, so nothing accumulates: what is measured is
     * the buffers. Ten times the rows at the same batch size must not cost ten times
     * the memory — with the eager version it did exactly that.
     */
    public function testPeakMemoryFollowsTheBatchNotTheRowCount(): void
    {
        $measure = function (int $rows): int {
            $this->cdo->exec('DELETE FROM inventory');
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();

            $this->cdo->insertBatch('inventory', $this->rows($rows), chunkSize: 200);

            return memory_get_peak_usage() - $before;
        };

        $small = $measure(1_000);
        $large = $measure(10_000);

        $this->assertSame(10_000, $this->rowCount(), 'the large run really did insert everything');
        $this->assertLessThan(
            $small * 3,
            $large,
            sprintf(
                'ten times the rows cost %d B instead of ~%d B — the batch is accumulating',
                $large,
                $small,
            ),
        );
    }

    /** A generator is never rewound, so it must be read exactly once. */
    public function testTheGeneratorIsConsumedOnce(): void
    {
        $yielded = 0;
        $counting = (function () use (&$yielded): Generator {
            foreach ($this->rows(50) as $row) {
                $yielded++;
                yield $row;
            }
        })();

        $this->cdo->insertBatch('inventory', $counting, chunkSize: 10);

        $this->assertSame(50, $yielded, 'each row was produced exactly once');
    }

    // ── Failure semantics, stated rather than assumed ──────────────────────────

    /**
     * Batches are sent as they fill, so a bad row leaves earlier batches committed.
     * The eager version validated everything first and wrote nothing — this is the
     * one behaviour the change trades away, and callers wanting all-or-nothing wrap
     * the call in a transaction.
     */
    public function testEarlierBatchesRemainCommittedWhenALaterRowFails(): void
    {
        $withBadRow = (static function (): Generator {
            yield ['sku' => 'A1', 'name' => 'Widget'];
            yield ['sku' => 'B2', 'name' => 'Gadget'];
            yield ['sku' => null, 'name' => null];      // rejected
        })();

        try {
            $this->cdo->insertBatch('inventory', $withBadRow, chunkSize: 2);
            $this->fail('the invalid row should have thrown');
        } catch (CDOException) {
            // expected
        }

        $this->assertSame(2, $this->rowCount(), 'the first full batch is already in the table');
    }

    /** …and a transaction is how a caller gets the old all-or-nothing guarantee. */
    public function testATransactionRestoresAllOrNothing(): void
    {
        $withBadRow = (static function (): Generator {
            yield ['sku' => 'A1', 'name' => 'Widget'];
            yield ['sku' => 'B2', 'name' => 'Gadget'];
            yield ['sku' => null, 'name' => null];
        })();

        try {
            $this->cdo->transaction(function () use ($withBadRow): void {
                $this->cdo->insertBatch('inventory', $withBadRow, chunkSize: 2);
            });
            $this->fail('the invalid row should have thrown');
        } catch (CDOException) {
            // expected
        }

        $this->assertSame(0, $this->rowCount(), 'the rollback took the committed batch with it');
    }
}

/** A PSR-3 logger that keeps what CDO tells it, so tests can count real statements. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $lines = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = (string) $message;
    }

    /** @return list<string> Every batch INSERT this connection sent. */
    public function inserts(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn(string $line): bool => str_starts_with($line, 'insert group:'),
        ));
    }

    public function reset(): void
    {
        $this->lines = [];
    }
}
