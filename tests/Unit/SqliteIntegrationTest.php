<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;
use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Cdo\Connection\CDOException;
use Flytachi\Winter\Cdo\Qb;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End-to-end SQLite tests exercising the real CDO surface against an in-memory
 * database (no external server required — the pdo_sqlite extension ships with
 * PHP). These prove that driver detection, quoting, insert/insertGroup,
 * update/delete and the PostgreSQL-style upsert path all work on SQLite.
 */
class SqliteIntegrationTest extends TestCase
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

    public function testDriverIsSqlite(): void
    {
        $this->assertSame('sqlite', $this->cdo->getDriverName());
    }

    public function testInsertReturnsLastInsertId(): void
    {
        $id = $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'Widget', 'qty' => 10]);
        $this->assertSame('1', (string) $id);
    }

    public function testInsertGroupReturnsCountAndHandlesHeterogeneousRows(): void
    {
        // Rows with differing null-patterns must not blow up (grouping fix).
        $n = $this->cdo->insertGroup('inventory', [
            ['sku' => 'B2', 'name' => 'Bob'],
            ['sku' => 'C3', 'name' => 'Carol', 'qty' => 5, 'price' => 1.2],
        ]);
        $this->assertSame(2, $n);
        $this->assertSame(2, (int) $this->cdo->query('SELECT count(*) FROM inventory')->fetchColumn());
    }

    public function testUpdateAndDelete(): void
    {
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'Widget']);
        $affected = $this->cdo->update('inventory', ['name' => 'Renamed'], Qb::eq('sku', 'A1'));
        $this->assertSame(1, $affected);
        $this->assertSame('Renamed', $this->cdo->query("SELECT name FROM inventory WHERE sku='A1'")->fetchColumn());

        $deleted = $this->cdo->delete('inventory', Qb::eq('sku', 'A1'));
        $this->assertSame(1, $deleted);
    }

    public function testUpsertDoNothingKeepsExistingRow(): void
    {
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'Widget']);
        $this->cdo->upsert('inventory', ['sku' => 'A1', 'name' => 'Should Not Win'], ['sku']);
        $this->assertSame('Widget', $this->cdo->query("SELECT name FROM inventory WHERE sku='A1'")->fetchColumn());
    }

    public function testUpsertDoUpdateWithNewValue(): void
    {
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'Widget']);
        $this->cdo->upsert('inventory', ['sku' => 'A1', 'name' => 'Widget2'], ['sku'], ['name' => ':new']);
        $this->assertSame('Widget2', $this->cdo->query("SELECT name FROM inventory WHERE sku='A1'")->fetchColumn());
    }

    public function testUpsertAccumulateUsesCurrentAndNew(): void
    {
        // Exercises the table-qualified `:current` reference on SQLite.
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'W', 'qty' => 10]);
        $this->cdo->upsert('inventory', ['sku' => 'A1', 'name' => 'W', 'qty' => 5], ['sku'], ['qty' => ':current + :new']);
        $this->assertSame(15, (int) $this->cdo->query("SELECT qty FROM inventory WHERE sku='A1'")->fetchColumn());
    }

    public function testUpsertGroupMixesInsertAndUpdate(): void
    {
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'W', 'qty' => 10]);
        $this->cdo->upsertGroup(
            'inventory',
            [
                ['sku' => 'A1', 'name' => 'W', 'qty' => 100], // existing -> 10 + 100
                ['sku' => 'B2', 'name' => 'Gadget', 'qty' => 7], // new
            ],
            ['sku'],
            ['qty' => ':current + :new', 'name' => ':new']
        );

        $this->assertSame(110, (int) $this->cdo->query("SELECT qty FROM inventory WHERE sku='A1'")->fetchColumn());
        $this->assertSame(7, (int) $this->cdo->query("SELECT qty FROM inventory WHERE sku='B2'")->fetchColumn());
    }

    public function testInsertGroupRejectsAllNullRow(): void
    {
        $this->expectException(CDOException::class);
        $this->cdo->insertGroup('inventory', [
            ['id' => null, 'name' => null],
        ]);
    }

    public function testInsertAcceptsObjects(): void
    {
        $entity = new \stdClass();
        $entity->id = null;
        $entity->sku = 'OBJ';
        $entity->name = 'FromObject';

        $id = $this->cdo->insert('inventory', $entity);
        $this->assertSame('1', (string) $id);
        $this->assertSame('FromObject', $this->cdo->query("SELECT name FROM inventory WHERE sku='OBJ'")->fetchColumn());
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $this->cdo->transaction(function (): void {
            $this->cdo->insert('inventory', ['id' => null, 'sku' => 'T1', 'name' => 'A']);
            $this->cdo->insert('inventory', ['id' => null, 'sku' => 'T2', 'name' => 'B']);
        });

        $this->assertSame(2, (int) $this->cdo->query('SELECT count(*) FROM inventory')->fetchColumn());
    }

    public function testTransactionRollsBackOnThrow(): void
    {
        try {
            $this->cdo->transaction(function (): void {
                $this->cdo->insert('inventory', ['id' => null, 'sku' => 'T1', 'name' => 'A']);
                throw new RuntimeException('boom');
            });
            $this->fail('Exception was expected to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // The insert before the throw must have been rolled back.
        $this->assertSame(0, (int) $this->cdo->query('SELECT count(*) FROM inventory')->fetchColumn());
    }

    public function testUpsertGroupMultiColumnConflict(): void
    {
        $this->cdo->exec(
            'CREATE TABLE stock (
                warehouse_id INTEGER,
                product_id INTEGER,
                qty INTEGER,
                PRIMARY KEY (warehouse_id, product_id)
            )'
        );

        $this->cdo->insert('stock', ['warehouse_id' => 1, 'product_id' => 100, 'qty' => 10]);
        $this->cdo->upsertGroup(
            'stock',
            [
                ['warehouse_id' => 1, 'product_id' => 100, 'qty' => 5], // existing -> 15
                ['warehouse_id' => 1, 'product_id' => 200, 'qty' => 3], // new
            ],
            ['warehouse_id', 'product_id'],
            ['qty' => ':current + :new']
        );

        $this->assertSame(15, (int) $this->cdo->query('SELECT qty FROM stock WHERE product_id=100')->fetchColumn());
        $this->assertSame(3, (int) $this->cdo->query('SELECT qty FROM stock WHERE product_id=200')->fetchColumn());
    }

    public function testQbBooleanIsTrueWorksOnSqlite(): void
    {
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A1', 'name' => 'On', 'qty' => 1, 'price' => 1.0]);
        $this->cdo->insert('inventory', ['id' => null, 'sku' => 'A2', 'name' => 'Off', 'qty' => 0]);
        // active column isn't defined here, so use a boolean expression column:
        $sql = 'SELECT count(*) FROM inventory WHERE ' . Qb::eq('qty', true)->getQuery();
        // Qb::eq(col, true) => "qty IS TRUE" — SQLite (>=3.23) understands it.
        $this->assertSame(1, (int) $this->cdo->query($sql)->fetchColumn());
    }

    public function testPingSucceeds(): void
    {
        $config = new SqliteDbCall();
        $this->assertTrue($config->ping());
        $detail = $config->pingDetail();
        $this->assertTrue($detail['status']);
        $this->assertNull($detail['error']);
    }
}
