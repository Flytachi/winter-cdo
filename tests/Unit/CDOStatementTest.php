<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\Connection\CDOStatement;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CDOStatement — type-aware PDO statement wrapper.
 *
 * Covers:
 *  - bindTypedValue → correct PDO::PARAM_* dispatch for each PHP type
 *  - valObject → priority order for object serialisation
 *  - getBindings → recorded binding list
 *  - updateStm → bindings replayed on a new PDOStatement
 *
 * We use PHPUnit's createMock(PDOStatement::class) so no real database is needed.
 */
class CDOStatementTest extends TestCase
{
    // ─── bindTypedValue — type dispatch ──────────────────────────────────

    public function testBindTypedValueNull(): void
    {
        $pdo = $this->mockPdo(PDO::PARAM_NULL, null);
        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':col', null);
    }

    public function testBindTypedValueBool(): void
    {
        $pdo = $this->mockPdo(PDO::PARAM_BOOL, true);
        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':col', true);
    }

    public function testBindTypedValueInt(): void
    {
        $pdo = $this->mockPdo(PDO::PARAM_INT, 42);
        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':col', 42);
    }

    public function testBindTypedValueString(): void
    {
        $pdo = $this->mockPdo(PDO::PARAM_STR, 'hello');
        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':col', 'hello');
    }

    public function testBindTypedValueFloat(): void
    {
        // Float falls through to the default (PARAM_STR)
        $pdo = $this->mockPdo(PDO::PARAM_STR, 3.14);
        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':col', 3.14);
    }

    public function testBindTypedValueArrayIsJsonEncoded(): void
    {
        $arr     = ['a', 'b'];
        $encoded = json_encode($arr);

        $pdo = $this->mockPdo(PDO::PARAM_STR, $encoded);
        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':col', $arr);
    }

    // ─── valObject — object serialisation ────────────────────────────────

    public function testValObjectStringable(): void
    {
        $obj = new class implements \Stringable {
            public function __toString(): string
            {
                return 'string-value';
            }
        };

        $stmt   = new CDOStatement($this->createStub(PDOStatement::class));
        $result = $stmt->valObject($obj);
        $this->assertSame('string-value', $result);
    }

    public function testValObjectDateTime(): void
    {
        $dt   = new \DateTimeImmutable('2024-06-15 10:30:00');
        $stmt = new CDOStatement($this->createStub(PDOStatement::class));

        $result = $stmt->valObject($dt);
        $this->assertSame('2024-06-15 10:30:00', $result);
    }

    public function testValObjectBackedEnum(): void
    {
        $stmt   = new CDOStatement($this->createStub(PDOStatement::class));
        $result = $stmt->valObject(TestStatus::Active);
        $this->assertSame('active', $result);
    }

    public function testValObjectJsonSerializable(): void
    {
        $obj = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['key' => 'val'];
            }
        };

        $stmt   = new CDOStatement($this->createStub(PDOStatement::class));
        $result = $stmt->valObject($obj);
        $this->assertSame(['key' => 'val'], $result);
    }

    public function testValObjectJsonSerializableTakesPriorityOverStringable(): void
    {
        // Implements both — JsonSerializable must win
        $obj = new class implements \JsonSerializable, \Stringable {
            public function jsonSerialize(): mixed
            {
                return 'from-json';
            }
            public function __toString(): string
            {
                return 'from-string';
            }
        };

        $stmt   = new CDOStatement($this->createStub(PDOStatement::class));
        $result = $stmt->valObject($obj);
        $this->assertSame('from-json', $result);
    }

    public function testValObjectFallbackToSerialize(): void
    {
        $obj      = new \stdClass();
        $obj->foo = 'bar';

        $stmt = new CDOStatement($this->createStub(PDOStatement::class));

        $result = $stmt->valObject($obj);
        $this->assertSame(serialize($obj), $result);
    }

    // ─── getBindings ──────────────────────────────────────────────────────

    public function testGetBindingsRecordsAllBindings(): void
    {
        $pdo = $this->createStub(PDOStatement::class);
        $pdo->method('bindValue')->willReturn(true);

        $stmt = new CDOStatement($pdo);
        $stmt->bindTypedValue(':a', 1);
        $stmt->bindTypedValue(':b', 'hello');
        $stmt->bindTypedValue(':c', null);

        $bindings = $stmt->getBindings();
        $this->assertCount(3, $bindings);
        $this->assertSame(':a', $bindings[0][0]);
        $this->assertSame(1, $bindings[0][1]);
        $this->assertSame(PDO::PARAM_INT, $bindings[0][2]);
        $this->assertSame(':b', $bindings[1][0]);
        $this->assertSame(':c', $bindings[2][0]);
        $this->assertNull($bindings[2][1]);
        $this->assertSame(PDO::PARAM_NULL, $bindings[2][2]);
    }

    // ─── updateStm ───────────────────────────────────────────────────────

    public function testUpdateStmReplaysBindingsOnNewStatement(): void
    {
        // First statement — records two bindings
        $pdo1 = $this->createStub(PDOStatement::class);
        $pdo1->method('bindValue')->willReturn(true);

        $stmt = new CDOStatement($pdo1);
        $stmt->bindTypedValue(':x', 10);
        $stmt->bindTypedValue(':y', 'test');

        // Second statement — must receive the same two bindValue calls
        $pdo2 = $this->createMock(PDOStatement::class);
        $pdo2->expects($this->exactly(2))
             ->method('bindValue')
             ->willReturn(true);

        $stmt->updateStm($pdo2);

        // getStmt() must now return the new statement
        $this->assertSame($pdo2, $stmt->getStmt());
    }

    // ─── getStmt ─────────────────────────────────────────────────────────

    public function testGetStmtReturnsWrappedStatement(): void
    {
        $pdo  = $this->createStub(PDOStatement::class);
        $stmt = new CDOStatement($pdo);
        $this->assertSame($pdo, $stmt->getStmt());
    }

    // ─── Helper ──────────────────────────────────────────────────────────

    /**
     * Creates a PDOStatement mock that asserts bindValue is called with the
     * expected type constant (and optionally a specific value).
     */
    private function mockPdo(int $expectedType, mixed $expectedValue = null): PDOStatement
    {
        $mock = $this->createMock(PDOStatement::class);
        $mock->expects($this->once())
             ->method('bindValue')
             ->with(
                 $this->anything(),
                 $expectedValue !== null ? $this->equalTo($expectedValue) : $this->anything(),
                 $this->equalTo($expectedType)
             )
             ->willReturn(true);
        return $mock;
    }
}

// ─── Fixtures ────────────────────────────────────────────────────────────────

enum TestStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
}
