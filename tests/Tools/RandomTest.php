<?php

declare(strict_types=1);

namespace Tests\Tools;

use PHPUnit\Framework\TestCase;
use Tools\Demo\Random;

/** The demo generator's random source: reproducible, and distributions shaped as intended. */
final class RandomTest extends TestCase
{
    public function testSameSeedSameSequenceAndIsolatedFromMtRand(): void
    {
        $a = new Random(42);
        $b = new Random(42);
        mt_srand(1); // the global generator must not interfere
        $seqA = array_map(static fn () => $a->int(0, 1000), range(1, 20));
        mt_rand();
        $seqB = array_map(static fn () => $b->int(0, 1000), range(1, 20));
        self::assertSame($seqA, $seqB);
    }

    public function testDifferentSeedsDiffer(): void
    {
        $a = new Random(1);
        $b = new Random(2);
        self::assertNotSame(
            array_map(static fn () => $a->int(0, 1_000_000), range(1, 10)),
            array_map(static fn () => $b->int(0, 1_000_000), range(1, 10)),
        );
    }

    public function testWeightedFollowsTheWeights(): void
    {
        $r = new Random(7);
        $counts = ['a' => 0, 'b' => 0, 'c' => 0];
        for ($i = 0; $i < 20000; $i++) {
            $counts[$r->weighted(['a' => 0.7, 'b' => 0.2, 'c' => 0.1])]++;
        }
        self::assertEqualsWithDelta(0.7, $counts['a'] / 20000, 0.02);
        self::assertEqualsWithDelta(0.2, $counts['b'] / 20000, 0.02);
        self::assertEqualsWithDelta(0.1, $counts['c'] / 20000, 0.02);
        $zero = new Random(1);
        for ($i = 0; $i < 200; $i++) {
            self::assertSame('kept', $zero->weighted(['never' => 0, 'kept' => 1]), 'a zero weight is never drawn');
        }
    }

    public function testPoissonMeanAndZipfSkew(): void
    {
        $r = new Random(11);
        foreach ([0.8, 4.5, 60.0] as $mean) {
            $sum = 0;
            for ($i = 0; $i < 5000; $i++) {
                $sum += $r->poisson($mean);
            }
            self::assertEqualsWithDelta($mean, $sum / 5000, max(0.08, $mean * 0.03), "poisson({$mean})");
        }
        self::assertSame(0, $r->poisson(0));

        $hits = array_fill(0, 10, 0);
        for ($i = 0; $i < 10000; $i++) {
            $hits[$r->zipf(10, 1.1)]++;
        }
        self::assertGreaterThan($hits[1], $hits[0], 'first correspondent most frequent');
        self::assertGreaterThan($hits[9] * 5, $hits[0], 'long tail');
    }

    public function testLogNormalHasTheRequestedMedianAndALongTail(): void
    {
        $r = new Random(3);
        $values = array_map(static fn () => $r->logNormal(6.0, 0.9), range(1, 5001));
        sort($values);
        self::assertEqualsWithDelta(6.0, $values[2500], 0.4, 'median');
        self::assertGreaterThan(20.0, $values[4900], 'some mail takes much longer');
        self::assertGreaterThan(0.0, $values[0]);
    }
}
