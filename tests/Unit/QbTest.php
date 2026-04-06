<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cdo\Tests\Unit;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Qb — parameterised SQL condition builder.
 *
 * Because $placeholderCounter is a global static, placeholder *names* (:iqb0,
 * :iqb1, …) depend on execution order across the entire test suite.  We therefore
 * verify:
 *   - SQL *structure* via regex or contains-assertions
 *   - Bind *values* (regardless of placeholder name)
 *   - Bind *count* (number of generated binds)
 *
 * Where an exact placeholder name matters (CDOBind reuse), we use a manually
 * constructed CDOBind so the name is deterministic.
 */
class QbTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────

    /** Returns bind values in order. */
    private function values(Qb $qb): array
    {
        return array_map(fn(CDOBind $b) => $b->getValue(), $qb->getBinds());
    }

    /** Assert SQL matches a regex, ignoring auto-placeholder name. */
    private function assertSqlMatches(string $pattern, Qb $qb): void
    {
        $this->assertMatchesRegularExpression($pattern, $qb->getQuery());
    }

    // ─── empty / getQuery / getBinds ─────────────────────────────────────

    public function testEmptyReturnsEmptyQuery(): void
    {
        $qb = Qb::empty();
        $this->assertSame('', $qb->getQuery());
        $this->assertSame([], $qb->getBinds());
    }

    public function testGetDataReturnsQueryAndBinds(): void
    {
        $qb   = Qb::empty();
        $data = $qb->getData();
        $this->assertArrayHasKey('query', $data);
        $this->assertArrayHasKey('binds', $data);
    }

    // ─── eq ──────────────────────────────────────────────────────────────

    public function testEqWithScalar(): void
    {
        $qb = Qb::eq('status', 'active');
        $this->assertSqlMatches('/^status = :iqb\d+$/', $qb);
        $this->assertSame(['active'], $this->values($qb));
    }

    public function testEqWithNull(): void
    {
        $qb = Qb::eq('deleted_at', null);
        $this->assertSame('deleted_at IS NULL', $qb->getQuery());
        $this->assertSame([], $this->values($qb));
    }

    public function testEqWithBoolTrue(): void
    {
        $qb = Qb::eq('is_active', true);
        $this->assertSame('is_active IS TRUE', $qb->getQuery());
        $this->assertSame([], $this->values($qb));
    }

    public function testEqWithBoolFalse(): void
    {
        $qb = Qb::eq('is_bot', false);
        $this->assertSame('is_bot IS FALSE', $qb->getQuery());
        $this->assertSame([], $this->values($qb));
    }

    public function testEqWithCDOBind(): void
    {
        $bind = new CDOBind('my_id', 99);
        $qb   = Qb::eq('id', $bind);
        $this->assertSame('id = :my_id', $qb->getQuery());
        $this->assertSame([99], $this->values($qb));
    }

    public function testEqWithInt(): void
    {
        $qb = Qb::eq('count', 5);
        $this->assertSqlMatches('/^count = :iqb\d+$/', $qb);
        $this->assertSame([5], $this->values($qb));
    }

    // ─── neq ─────────────────────────────────────────────────────────────

    public function testNeqWithScalar(): void
    {
        $qb = Qb::neq('role', 'banned');
        $this->assertSqlMatches('/^role != :iqb\d+$/', $qb);
        $this->assertSame(['banned'], $this->values($qb));
    }

    public function testNeqWithNull(): void
    {
        $qb = Qb::neq('email', null);
        $this->assertSame('email IS NOT NULL', $qb->getQuery());
        $this->assertCount(0, $qb->getBinds());
    }

    public function testNeqWithBoolTrue(): void
    {
        $qb = Qb::neq('is_trial', true);
        $this->assertSame('is_trial IS NOT TRUE', $qb->getQuery());
    }

    public function testNeqWithBoolFalse(): void
    {
        $qb = Qb::neq('is_bot', false);
        $this->assertSame('is_bot IS NOT FALSE', $qb->getQuery());
    }

    // ─── gt / gte / lt / lte ─────────────────────────────────────────────

    public function testGt(): void
    {
        $qb = Qb::gt('price', 100);
        $this->assertSqlMatches('/^price > :iqb\d+$/', $qb);
        $this->assertSame([100], $this->values($qb));
    }

    public function testGte(): void
    {
        $qb = Qb::gte('score', 60);
        $this->assertSqlMatches('/^score >= :iqb\d+$/', $qb);
        $this->assertSame([60], $this->values($qb));
    }

    public function testLt(): void
    {
        $qb = Qb::lt('stock', 10);
        $this->assertSqlMatches('/^stock < :iqb\d+$/', $qb);
        $this->assertSame([10], $this->values($qb));
    }

    public function testLte(): void
    {
        $qb = Qb::lte('age', 65);
        $this->assertSqlMatches('/^age <= :iqb\d+$/', $qb);
        $this->assertSame([65], $this->values($qb));
    }

    public function testGtWithFloat(): void
    {
        $qb = Qb::gt('ratio', 0.5);
        $this->assertSame([0.5], $this->values($qb));
    }

    // ─── nsEq ────────────────────────────────────────────────────────────

    public function testNsEq(): void
    {
        $qb = Qb::nsEq('score', 0);
        $this->assertSqlMatches('/^score <=> :iqb\d+$/', $qb);
        $this->assertSame([0], $this->values($qb));
    }

    // ─── isNull / isNotNull ───────────────────────────────────────────────

    public function testIsNull(): void
    {
        $qb = Qb::isNull('deleted_at');
        $this->assertSame('deleted_at IS NULL', $qb->getQuery());
        $this->assertCount(0, $qb->getBinds());
    }

    public function testIsNotNull(): void
    {
        $qb = Qb::isNotNull('email');
        $this->assertSame('email IS NOT NULL', $qb->getQuery());
        $this->assertCount(0, $qb->getBinds());
    }

    // ─── in / notIn ───────────────────────────────────────────────────────

    public function testIn(): void
    {
        $qb = Qb::in('status', ['active', 'pending']);
        $this->assertSqlMatches('/^status IN \(:iqb\d+, :iqb\d+\)$/', $qb);
        $this->assertSame(['active', 'pending'], $this->values($qb));
    }

    public function testInWithEmptyArrayReturnsEmpty(): void
    {
        $qb = Qb::in('id', []);
        $this->assertSame('', $qb->getQuery());
        $this->assertCount(0, $qb->getBinds());
    }

    public function testNotIn(): void
    {
        $qb = Qb::notIn('role', ['banned', 'ghost']);
        $this->assertSqlMatches('/^role NOT IN \(:iqb\d+, :iqb\d+\)$/', $qb);
        $this->assertSame(['banned', 'ghost'], $this->values($qb));
    }

    public function testNotInWithEmptyArrayReturnsEmpty(): void
    {
        $qb = Qb::notIn('id', []);
        $this->assertSame('', $qb->getQuery());
    }

    public function testInWithSingleValue(): void
    {
        $qb = Qb::in('id', [42]);
        $this->assertSqlMatches('/^id IN \(:iqb\d+\)$/', $qb);
        $this->assertSame([42], $this->values($qb));
    }

    // ─── like / notLike ──────────────────────────────────────────────────

    public function testLike(): void
    {
        $qb = Qb::like('name', '%john%');
        $this->assertSqlMatches('/^name LIKE :iqb\d+$/', $qb);
        $this->assertSame(['%john%'], $this->values($qb));
    }

    public function testLikeInsensitive(): void
    {
        $qb = Qb::like('name', '%john%', insensitive: true);
        $this->assertSqlMatches('/^name ILIKE :iqb\d+$/', $qb);
    }

    public function testNotLike(): void
    {
        $qb = Qb::notLike('email', '%spam%');
        $this->assertSqlMatches('/^email NOT LIKE :iqb\d+$/', $qb);
        $this->assertSame(['%spam%'], $this->values($qb));
    }

    public function testNotLikeInsensitive(): void
    {
        $qb = Qb::notLike('name', '%bot%', insensitive: true);
        $this->assertSqlMatches('/^name NOT ILIKE :iqb\d+$/', $qb);
    }

    // ─── between ─────────────────────────────────────────────────────────

    public function testBetween(): void
    {
        $qb = Qb::between('age', 18, 65);
        $this->assertSqlMatches('/^age BETWEEN :iqb\d+ AND :iqb\d+$/', $qb);
        $this->assertSame([18, 65], $this->values($qb));
    }

    public function testNotBetween(): void
    {
        $qb = Qb::notBetween('price', 10, 50);
        $this->assertSqlMatches('/^price NOT BETWEEN :iqb\d+ AND :iqb\d+$/', $qb);
        $this->assertSame([10, 50], $this->values($qb));
    }

    public function testBetweenBy(): void
    {
        $qb = Qb::betweenBy('2024-06-01', 'valid_from', 'valid_to');
        $this->assertSqlMatches('/^:iqb\d+ BETWEEN valid_from AND valid_to$/', $qb);
        $this->assertSame(['2024-06-01'], $this->values($qb));
    }

    public function testNotBetweenBy(): void
    {
        $qb = Qb::notBetweenBy('2020-01-01', 'valid_from', 'valid_to');
        $this->assertSqlMatches('/^:iqb\d+ NOT BETWEEN valid_from AND valid_to$/', $qb);
        $this->assertSame(['2020-01-01'], $this->values($qb));
    }

    public function testBetweenWithNamedBinds(): void
    {
        $min = new CDOBind('price_min', 100);
        $max = new CDOBind('price_max', 500);
        $qb  = Qb::between('price', $min, $max);
        $this->assertSame('price BETWEEN :price_min AND :price_max', $qb->getQuery());
        $this->assertSame([100, 500], $this->values($qb));
    }

    // ─── and / or / xor ──────────────────────────────────────────────────

    public function testAnd(): void
    {
        $qb = Qb::and(
            Qb::eq('a', 1),
            Qb::eq('b', 2),
        );
        $this->assertSqlMatches('/^a = :iqb\d+ AND b = :iqb\d+$/', $qb);
        $this->assertSame([1, 2], $this->values($qb));
    }

    public function testOr(): void
    {
        $qb = Qb::or(
            Qb::eq('role', 'admin'),
            Qb::eq('role', 'mod'),
        );
        $this->assertSqlMatches('/^role = :iqb\d+ OR role = :iqb\d+$/', $qb);
        $this->assertSame(['admin', 'mod'], $this->values($qb));
    }

    public function testXor(): void
    {
        $qb = Qb::xor(
            Qb::eq('flag_a', 1),
            Qb::eq('flag_b', 1),
        );
        $this->assertSqlMatches('/^flag_a = :iqb\d+ XOR flag_b = :iqb\d+$/', $qb);
    }

    public function testAndSkipsNullConditions(): void
    {
        $qb = Qb::and(
            Qb::eq('a', 1),
            null,
            Qb::eq('b', 2),
        );
        $this->assertSqlMatches('/^a = :iqb\d+ AND b = :iqb\d+$/', $qb);
        $this->assertCount(2, $qb->getBinds());
    }

    public function testAndSkipsEmptyConditions(): void
    {
        $qb = Qb::and(
            Qb::eq('a', 1),
            Qb::empty(),
            Qb::eq('b', 2),
        );
        $this->assertSqlMatches('/^a = :iqb\d+ AND b = :iqb\d+$/', $qb);
        $this->assertCount(2, $qb->getBinds());
    }

    public function testOrSkipsNullConditions(): void
    {
        $qb = Qb::or(null, Qb::eq('x', 1), null);
        $this->assertSqlMatches('/^x = :iqb\d+$/', $qb);
    }

    public function testAndWithAllNullsReturnsEmpty(): void
    {
        $qb = Qb::and(null, null, null);
        $this->assertSame('', $qb->getQuery());
        $this->assertCount(0, $qb->getBinds());
    }

    // ─── clip ────────────────────────────────────────────────────────────

    public function testClipWrapsConditionInParentheses(): void
    {
        $inner = Qb::or(Qb::eq('a', 1), Qb::eq('b', 2));
        $clipped = Qb::clip($inner);

        $this->assertStringStartsWith('(', $clipped->getQuery());
        $this->assertStringEndsWith(')', $clipped->getQuery());
        $this->assertSame($inner->getBinds(), $clipped->getBinds());
    }

    public function testClipOnEmptyReturnsEmpty(): void
    {
        $clipped = Qb::clip(Qb::empty());
        $this->assertSame('', $clipped->getQuery());
    }

    public function testClipPreservesBinds(): void
    {
        $inner   = Qb::and(Qb::eq('x', 10), Qb::eq('y', 20));
        $clipped = Qb::clip($inner);
        $this->assertSame([10, 20], $this->values($clipped));
    }

    // ─── Precedence with clip ─────────────────────────────────────────────

    public function testAndOrPrecedenceWithClip(): void
    {
        $qb = Qb::and(
            Qb::eq('published', true),
            Qb::clip(
                Qb::or(Qb::eq('role', 'editor'), Qb::eq('role', 'admin'))
            ),
        );
        // Must contain parentheses around the OR clause
        $sql = $qb->getQuery();
        $this->assertStringContainsString(' AND (', $sql);
        $this->assertStringEndsWith(')', $sql);
    }

    // ─── Mutable: addAnd / addOr / addXor ────────────────────────────────

    public function testAddAndAppendsCondition(): void
    {
        $qb = Qb::eq('a', 1);
        $qb->addAnd(Qb::eq('b', 2));

        $this->assertSqlMatches('/^a = :iqb\d+ AND b = :iqb\d+$/', $qb);
        $this->assertSame([1, 2], $this->values($qb));
    }

    public function testAddOrAppendsCondition(): void
    {
        $qb = Qb::eq('role', 'admin');
        $qb->addOr(Qb::eq('role', 'mod'));

        $this->assertSqlMatches('/^role = :iqb\d+ OR role = :iqb\d+$/', $qb);
    }

    public function testAddAndOnEmptyBase(): void
    {
        $qb = Qb::empty();
        $qb->addAnd(Qb::eq('x', 5));

        $this->assertSqlMatches('/^x = :iqb\d+$/', $qb);
        $this->assertSame([5], $this->values($qb));
    }

    public function testAddAndChained(): void
    {
        $qb = Qb::empty();
        $qb->addAnd(Qb::eq('a', 1));
        $qb->addAnd(Qb::eq('b', 2));
        $qb->addAnd(Qb::eq('c', 3));

        $this->assertCount(3, $qb->getBinds());
        $this->assertSame([1, 2, 3], $this->values($qb));
    }

    // ─── CDOBind reuse ───────────────────────────────────────────────────

    public function testSameCDOBindReusedAcrossConditions(): void
    {
        $uid = new CDOBind('uid', 42);

        $qb = Qb::or(
            Qb::eq('author_id', $uid),
            Qb::eq('reviewer_id', $uid),
        );

        $this->assertSame('author_id = :uid OR reviewer_id = :uid', $qb->getQuery());
        // Two binds, both with name ':uid' and value 42
        $this->assertCount(2, $qb->getBinds());
        foreach ($qb->getBinds() as $bind) {
            $this->assertSame(':uid', $bind->getName());
            $this->assertSame(42, $bind->getValue());
        }
    }

    // ─── case ─────────────────────────────────────────────────────────────

    public function testCaseWithElse(): void
    {
        $qb = Qb::case([
            'score >= 90' => 'A',
            'score >= 60' => 'B',
        ], else: 'F');

        $sql = $qb->getQuery();
        $this->assertStringStartsWith('CASE ', $sql);
        $this->assertStringContainsString('WHEN score >= 90 THEN', $sql);
        $this->assertStringContainsString('WHEN score >= 60 THEN', $sql);
        $this->assertStringContainsString('ELSE', $sql);
        $this->assertStringEndsWith('END', $sql);
        $this->assertSame(['A', 'B', 'F'], $this->values($qb));
    }

    public function testCaseWithoutElse(): void
    {
        $qb = Qb::case(['x > 0' => 'positive']);

        $this->assertStringNotContainsString('ELSE', $qb->getQuery());
        $this->assertSame(['positive'], $this->values($qb));
    }

    // ─── custom ───────────────────────────────────────────────────────────

    public function testCustomReturnsRawSql(): void
    {
        $qb = Qb::custom('JSON_CONTAINS(tags, \'"php"\')');
        $this->assertSame('JSON_CONTAINS(tags, \'"php"\')', $qb->getQuery());
        $this->assertCount(0, $qb->getBinds());
    }

    // ─── getCache (deprecated) ────────────────────────────────────────────

    public function testGetCacheAlwaysReturnsEmptyArray(): void
    {
        $qb = Qb::eq('id', 1);
        $this->assertSame([], $qb->getCache());
    }

    // ─── Complex combinations ─────────────────────────────────────────────

    public function testComplexAndOrWithClip(): void
    {
        $qb = Qb::and(
            Qb::eq('published', true),
            Qb::gte('age', 18),
            Qb::clip(
                Qb::or(
                    Qb::eq('role', 'admin'),
                    Qb::eq('role', 'editor'),
                )
            ),
        );

        $sql = $qb->getQuery();
        $this->assertStringContainsString('published IS TRUE', $sql);
        $this->assertStringContainsString(' AND ', $sql);
        $this->assertStringContainsString(' OR ', $sql);
        $this->assertStringContainsString('(', $sql);
        // Values: age=18, role='admin', role='editor'
        $this->assertContains(18, $this->values($qb));
        $this->assertContains('admin', $this->values($qb));
        $this->assertContains('editor', $this->values($qb));
    }

    public function testDynamicFilterWithNullsAndEmptyArray(): void
    {
        $status  = 'active';
        $minAge  = null;        // should be skipped
        $tagIds  = [];          // should be skipped (empty in())

        $qb = Qb::and(
            Qb::eq('status', $status),
            $minAge !== null ? Qb::gte('age', $minAge) : null,
            Qb::in('tag_id', $tagIds),
        );

        // Only status condition should survive
        $this->assertSqlMatches('/^status = :iqb\d+$/', $qb);
        $this->assertSame(['active'], $this->values($qb));
    }
}
