<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `$updateColumns` takes a column => expression map, and a plain list is refused.
 *
 * Passing `['qty', 'created_at']` is a natural mistake — it is exactly the shape
 * Laravel's `upsert()` takes for the same argument — and it used to reach the database
 * as `SET 0 = qty`, which came back as `no such column: 0`. That message points at the
 * schema, not at the call, so the reader goes looking in the wrong place.
 *
 * Accepting the list as shorthand for `:new` was considered and turned down: it only
 * covers the trivial case, so the map has to be learned anyway the first time an
 * expression is needed, and the cost is two shapes to carry forever. One shape, and an
 * error that teaches the rest of the API, is the trade taken here.
 */
class UpsertUpdateColumnsShapeTest extends TestCase
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

    /** @return array<string, mixed> */
    private function row(): array
    {
        return ['sku' => 'A1', 'name' => 'Widget', 'qty' => 1];
    }

    private function qty(): int
    {
        return (int) $this->cdo->query("SELECT qty FROM inventory WHERE sku='A1'")->fetchColumn();
    }

    // ── The refusal ────────────────────────────────────────────────────────────

    public function testAPlainListIsRefusedByUpsert(): void
    {
        $this->expectException(CDOException::class);
        $this->expectExceptionMessage('updateColumns expects a column => expression map');

        $this->cdo->upsert('inventory', $this->row(), ['sku'], ['qty']);
    }

    public function testAPlainListIsRefusedByUpsertBatch(): void
    {
        $this->expectException(CDOException::class);
        $this->expectExceptionMessage('updateColumns expects a column => expression map');

        $this->cdo->upsertBatch('inventory', [$this->row()], ['sku'], ['qty']);
    }

    /** The message has to name the offending column and spell out the fix. */
    public function testTheMessageShowsTheCorrectedCall(): void
    {
        try {
            $this->cdo->upsertBatch('inventory', [$this->row()], ['sku'], ['qty', 'name']);
            $this->fail('a plain list should have been refused');
        } catch (CDOException $e) {
            $this->assertStringContainsString("['qty' => ':new']", $e->getMessage());
            $this->assertStringContainsString("':current'", $e->getMessage(), 'it teaches the other placeholder');
            $this->assertStringContainsString('position 0', $e->getMessage());
        }
    }

    /** A half-list is still a list, and the refusal must not be fooled by the map half. */
    public function testAMixedShapeIsRefused(): void
    {
        $this->expectException(CDOException::class);

        $this->cdo->upsertBatch('inventory', [$this->row()], ['sku'], ['qty', 'name' => ':new']);
    }

    /**
     * …including when the map half comes first. Every key has to be examined: stopping
     * at the first string key would wave `['name' => ':new', 'qty']` straight through to
     * the `no such column: 0` this check exists to prevent.
     */
    public function testAListEntryIsFoundAfterMapEntries(): void
    {
        $this->expectException(CDOException::class);
        $this->expectExceptionMessage('position 0');

        $this->cdo->upsertBatch('inventory', [$this->row()], ['sku'], ['name' => ':new', 'qty']);
    }

    /** Nothing is written before the refusal — it is configuration, checked up front. */
    public function testNothingIsWrittenWhenTheShapeIsRefused(): void
    {
        try {
            $this->cdo->upsertBatch('inventory', [$this->row()], ['sku'], ['qty']);
        } catch (CDOException) {
            // expected
        }

        $this->assertSame(
            0,
            (int) $this->cdo->query('SELECT count(*) FROM inventory')->fetchColumn(),
        );
    }

    // ── What must keep working ─────────────────────────────────────────────────

    public function testTheMapShapeIsAccepted(): void
    {
        $this->cdo->insert('inventory', $this->row());
        $this->cdo->upsertBatch('inventory', [['sku' => 'A1', 'qty' => 9]], ['sku'], ['qty' => ':new']);

        $this->assertSame(9, $this->qty());
    }

    public function testExpressionsKeepWorking(): void
    {
        $this->cdo->insert('inventory', $this->row());
        $this->cdo->upsertBatch(
            'inventory',
            [['sku' => 'A1', 'qty' => 4]],
            ['sku'],
            ['qty' => ':current + :new'],
        );

        $this->assertSame(5, $this->qty());
    }

    /** An empty map is the documented way to say DO NOTHING — the check must not touch it. */
    public function testAnEmptyMapStillMeansDoNothing(): void
    {
        $this->cdo->insert('inventory', $this->row());
        $this->cdo->upsertBatch('inventory', [['sku' => 'A1', 'qty' => 99]], ['sku'], []);

        $this->assertSame(1, $this->qty(), 'the conflicting row was left untouched');
    }

    public function testNullStillMeansDoNothing(): void
    {
        $this->cdo->insert('inventory', $this->row());
        $this->cdo->upsertBatch('inventory', [['sku' => 'A1', 'qty' => 99]], ['sku'], null);

        $this->assertSame(1, $this->qty());
    }
}
