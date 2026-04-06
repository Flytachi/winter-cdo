<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\CDOBind;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CDOBind — named parameter container.
 *
 * Covers:
 *  - Colon-prefix normalisation
 *  - getName / getValue accessors
 *  - Mixed value types (null, bool, int, float, string)
 */
class CDOBindTest extends TestCase
{
    // ── Constructor / prefix normalisation ────────────────────────────────

    public function testNameWithoutColonGetsColonPrepended(): void
    {
        $bind = new CDOBind('user_id', 1);
        $this->assertSame(':user_id', $bind->getName());
    }

    public function testNameWithColonIsKeptAsIs(): void
    {
        $bind = new CDOBind(':user_id', 1);
        $this->assertSame(':user_id', $bind->getName());
    }

    public function testNameWithDoubleColonIsKeptAsIs(): void
    {
        // If someone passes '::foo' — we don't strip, just keep it
        $bind = new CDOBind('::foo', 1);
        $this->assertSame('::foo', $bind->getName());
    }

    // ── getValue ──────────────────────────────────────────────────────────

    public function testGetValueReturnsInt(): void
    {
        $bind = new CDOBind('n', 42);
        $this->assertSame(42, $bind->getValue());
    }

    public function testGetValueReturnsFloat(): void
    {
        $bind = new CDOBind('ratio', 3.14);
        $this->assertSame(3.14, $bind->getValue());
    }

    public function testGetValueReturnsString(): void
    {
        $bind = new CDOBind('name', 'Alice');
        $this->assertSame('Alice', $bind->getValue());
    }

    public function testGetValueReturnsNull(): void
    {
        $bind = new CDOBind('deleted_at', null);
        $this->assertNull($bind->getValue());
    }

    public function testGetValueReturnsBoolTrue(): void
    {
        $bind = new CDOBind('active', true);
        $this->assertTrue($bind->getValue());
    }

    public function testGetValueReturnsBoolFalse(): void
    {
        $bind = new CDOBind('active', false);
        $this->assertFalse($bind->getValue());
    }

    // ── Immutability (readonly class) ────────────────────────────────────

    public function testBindIsReadonly(): void
    {
        $bind = new CDOBind('id', 1);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $bind->name = 'changed'; // @phpcs:ignore
    }
}
