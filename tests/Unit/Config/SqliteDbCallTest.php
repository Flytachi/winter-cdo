<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit\Config;

use Flytachi\Winter\Cdo\Config\Call\SqliteDbCall;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SqliteDbCall — inline SQLite configuration.
 *
 * Covers: constructor default, DSN format (path / :memory:), driver string,
 * persistence default, setUp no-op.
 */
class SqliteDbCallTest extends TestCase
{
    // ─── Defaults ────────────────────────────────────────────────────────

    public function testDefaultPathIsMemory(): void
    {
        $this->assertSame(':memory:', (new SqliteDbCall())->path);
    }

    // ─── getDriver ───────────────────────────────────────────────────────

    public function testGetDriverReturnsSqlite(): void
    {
        $this->assertSame('sqlite', (new SqliteDbCall())->getDriver());
    }

    // ─── getDns ──────────────────────────────────────────────────────────

    public function testGetDnsForMemory(): void
    {
        $this->assertSame('sqlite::memory:', (new SqliteDbCall())->getDns());
    }

    public function testGetDnsForFilePath(): void
    {
        $config = new SqliteDbCall(path: '/var/data/app.sqlite');
        $this->assertSame('sqlite:/var/data/app.sqlite', $config->getDns());
    }

    public function testGetDnsHasNoHostPortDbname(): void
    {
        // SQLite must NOT use the generic host/port/dbname DSN shape.
        $dns = (new SqliteDbCall(path: '/tmp/x.db'))->getDns();
        $this->assertStringNotContainsString('host=', $dns);
        $this->assertStringNotContainsString('port=', $dns);
        $this->assertStringNotContainsString('dbname=', $dns);
    }

    // ─── getPersistentStatus ─────────────────────────────────────────────

    public function testPersistentStatusDefaultsFalse(): void
    {
        $this->assertFalse((new SqliteDbCall())->getPersistentStatus());
    }

    // ─── getSchema ───────────────────────────────────────────────────────

    public function testGetSchemaReturnsNull(): void
    {
        $this->assertNull((new SqliteDbCall())->getSchema());
    }

    // ─── setUp ───────────────────────────────────────────────────────────

    public function testSetUpIsNoOp(): void
    {
        $config = new SqliteDbCall();
        $config->setUp();
        $this->assertTrue(true);
    }
}
