<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit\Config;

use Flytachi\Winter\Cdo\Config\Call\PgDbCall;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PgDbCall — inline PostgreSQL configuration.
 *
 * Covers: constructor defaults, DSN format, driver string, schema.
 */
class PgDbCallTest extends TestCase
{
    // ─── Defaults ────────────────────────────────────────────────────────

    public function testDefaultValues(): void
    {
        $config = new PgDbCall();
        $this->assertSame('localhost', $config->host);
        $this->assertSame(5432, $config->port);
        $this->assertSame('postgres', $config->database);
        $this->assertSame('postgres', $config->username);
        $this->assertSame('', $config->password);
        $this->assertSame('public', $config->schema);
        $this->assertNull($config->charset);
    }

    // ─── getDriver ───────────────────────────────────────────────────────

    public function testGetDriverReturnsPgsql(): void
    {
        $this->assertSame('pgsql', (new PgDbCall())->getDriver());
    }

    // ─── getDns ──────────────────────────────────────────────────────────

    public function testGetDnsBasicFormat(): void
    {
        $config = new PgDbCall(
            host: '127.0.0.1',
            port: 5432,
            database: 'myapp',
        );
        $dns = $config->getDns();
        $this->assertStringContainsString('pgsql:', $dns);
        $this->assertStringContainsString('host=127.0.0.1', $dns);
        $this->assertStringContainsString('port=5432', $dns);
        $this->assertStringContainsString('dbname=myapp', $dns);
    }

    public function testGetDnsWithCharsetAppendsClientEncoding(): void
    {
        $config = new PgDbCall(charset: 'UTF8');
        $dns    = $config->getDns();
        $this->assertStringContainsString("--client_encoding=UTF8", $dns);
    }

    public function testGetDnsWithoutCharsetOmitsEncoding(): void
    {
        $config = new PgDbCall();
        $this->assertStringNotContainsString('client_encoding', $config->getDns());
    }

    public function testGetDnsDefaultsToSslmodeDisable(): void
    {
        $this->assertStringContainsString('sslmode=disable', (new PgDbCall())->getDns());
    }

    public function testGetDnsSslmodeCanBeOverridden(): void
    {
        $this->assertStringContainsString('sslmode=require', (new PgDbCall(sslmode: 'require'))->getDns());
    }

    public function testGetDnsEmptySslmodeOmitsKey(): void
    {
        $this->assertStringNotContainsString('sslmode', (new PgDbCall(sslmode: ''))->getDns());
    }

    // ─── getSchema ───────────────────────────────────────────────────────

    public function testGetDnsBoundsAWaitOnASilentNetworkByDefault(): void
    {
        // A socket dropped in silence otherwise holds a query until the OS gives up: minutes.
        $this->assertStringContainsString('tcp_user_timeout=10000;', (new PgDbCall())->getDns());
    }

    public function testGetDnsTcpUserTimeoutCanBeChangedOrOmitted(): void
    {
        $this->assertStringContainsString('tcp_user_timeout=3000;', (new PgDbCall(tcpUserTimeout: 3000))->getDns());
        $this->assertStringNotContainsString('tcp_user_timeout', (new PgDbCall(tcpUserTimeout: 0))->getDns());
    }

    public function testThePgDbConfigBaseBoundsItToo(): void
    {
        $config = new class extends \Flytachi\Winter\Cdo\Config\PgDbConfig {
            public function setUp(): void
            {
            }
        };
        $this->assertStringContainsString('tcp_user_timeout=10000;', $config->getDns());

        $off = new class extends \Flytachi\Winter\Cdo\Config\PgDbConfig {
            protected int $tcpUserTimeout = 0;
            public function setUp(): void
            {
            }
        };
        $this->assertStringNotContainsString('tcp_user_timeout', $off->getDns());
    }

    public function testGetSchemaReturnsDefault(): void
    {
        $this->assertSame('public', (new PgDbCall())->getSchema());
    }

    public function testGetSchemaReturnsCustomValue(): void
    {
        $config = new PgDbCall(schema: 'app_schema');
        $this->assertSame('app_schema', $config->getSchema());
    }

    // ─── getPersistentStatus ─────────────────────────────────────────────

    public function testPersistentStatusDefaultsFalse(): void
    {
        $this->assertFalse((new PgDbCall())->getPersistentStatus());
    }

    // ─── setUp ───────────────────────────────────────────────────────────

    public function testSetUpIsNoOp(): void
    {
        $config = new PgDbCall();
        // Must not throw
        $config->setUp();
        $this->assertTrue(true);
    }

    // ─── Custom values round-trip ─────────────────────────────────────────

    public function testCustomValuesRoundTrip(): void
    {
        $config = new PgDbCall(
            host:     'db.example.com',
            port:     5433,
            database: 'prod',
            username: 'app_user',
            password: 's3cret',
            schema:   'prod_schema',
            charset:  'UTF8',
        );

        $this->assertSame('db.example.com', $config->host);
        $this->assertSame(5433, $config->port);
        $this->assertSame('prod', $config->database);
        $this->assertSame('app_user', $config->username);
        $this->assertSame('s3cret', $config->password);
        $this->assertSame('prod_schema', $config->schema);
        $this->assertSame('UTF8', $config->charset);
    }
}
