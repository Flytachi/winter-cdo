<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit\Config;

use Flytachi\Winter\Cdo\Config\Call\MySqlDbCall;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MySqlDbCall — inline MySQL / MariaDB configuration.
 *
 * Covers: constructor defaults, DSN format, driver string.
 */
class MySqlDbCallTest extends TestCase
{
    // ─── Defaults ────────────────────────────────────────────────────────

    public function testDefaultValues(): void
    {
        $config = new MySqlDbCall();
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('', $config->database);
        $this->assertSame('root', $config->username);
        $this->assertSame('', $config->password);
        $this->assertNull($config->charset);
    }

    // ─── getDriver ───────────────────────────────────────────────────────

    public function testGetDriverReturnsMysql(): void
    {
        $this->assertSame('mysql', (new MySqlDbCall())->getDriver());
    }

    // ─── getDns ──────────────────────────────────────────────────────────

    public function testGetDnsBasicFormat(): void
    {
        $config = new MySqlDbCall(
            host:     '127.0.0.1',
            port:     3306,
            database: 'myapp',
        );
        $dns = $config->getDns();
        $this->assertStringContainsString('mysql:', $dns);
        $this->assertStringContainsString('host=127.0.0.1', $dns);
        $this->assertStringContainsString('port=3306', $dns);
        $this->assertStringContainsString('dbname=myapp', $dns);
    }

    public function testGetDnsWithCharsetAppendsCharset(): void
    {
        $config = new MySqlDbCall(charset: 'utf8mb4');
        $this->assertStringContainsString('charset=utf8mb4', $config->getDns());
    }

    public function testGetDnsWithoutCharsetOmitsCharset(): void
    {
        $config = new MySqlDbCall();
        $this->assertStringNotContainsString('charset=', $config->getDns());
    }

    // ─── getSchema ───────────────────────────────────────────────────────

    public function testGetSchemaReturnsNull(): void
    {
        $this->assertNull((new MySqlDbCall())->getSchema());
    }

    // ─── getPersistentStatus ─────────────────────────────────────────────

    public function testPersistentStatusDefaultsFalse(): void
    {
        $this->assertFalse((new MySqlDbCall())->getPersistentStatus());
    }

    // ─── setUp ───────────────────────────────────────────────────────────

    public function testSetUpIsNoOp(): void
    {
        $config = new MySqlDbCall();
        $config->setUp();
        $this->assertTrue(true);
    }

    // ─── Custom values round-trip ─────────────────────────────────────────

    public function testCustomValuesRoundTrip(): void
    {
        $config = new MySqlDbCall(
            host:     'db.example.com',
            port:     3307,
            database: 'prod',
            username: 'app_user',
            password: 's3cret',
            charset:  'utf8mb4',
        );

        $this->assertSame('db.example.com', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertSame('prod', $config->database);
        $this->assertSame('app_user', $config->username);
        $this->assertSame('s3cret', $config->password);
        $this->assertSame('utf8mb4', $config->charset);
    }
}
