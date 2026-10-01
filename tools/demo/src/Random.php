<?php

declare(strict_types=1);

namespace Tools\Demo;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded random source for demo data: same seed, same data.
 * Its own engine: does not touch PHP's global mt_rand state.
 */
final class Random
{
    private readonly Randomizer $randomizer;

    /** @var array<string, list<float>> cumulative Zipf weights cache */
    private array $zipf = [];

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /** Uniform in [0, 1). */
    public function float(): float
    {
        return $this->randomizer->getInt(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new InvalidArgumentException('Cannot pick from an empty list.');
        }
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * Key chosen with probability proportional to its weight.
     *
     * @param array<int|string, float|int> $weights
     */
    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            throw new InvalidArgumentException('Weights must add up to more than 0.');
        }
        $target = $this->float() * $total;
        foreach ($weights as $key => $weight) {
            $target -= $weight;
            if ($target < 0) {
                return $key;
            }
        }
        return array_key_last($weights);
    }

    /** Standard normal (Box–Muller). */
    public function normal(): float
    {
        $u = max($this->float(), 1e-12);
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $this->float());
    }

    /** Log-normal with the given median: most values near it, a long tail above. */
    public function logNormal(float $median, float $sigma): float
    {
        return $median * exp($sigma * $this->normal());
    }

    /** Poisson-distributed count (Knuth; normal approximation for large means). */
    public function poisson(float $mean): int
    {
        if ($mean <= 0) {
            return 0;
        }
        if ($mean > 30) {
            return max(0, (int) round($mean + sqrt($mean) * $this->normal()));
        }
        $limit = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= $this->float();
        } while ($p > $limit);
        return $k - 1;
    }

    /** Index in [0, n) with Zipf probabilities: index 0 most frequent. */
    public function zipf(int $n, float $exponent): int
    {
        $key = "{$n}:{$exponent}";
        if (!isset($this->zipf[$key])) {
            $cumulative = [];
            $sum = 0.0;
            for ($i = 1; $i <= $n; $i++) {
                $sum += 1 / ($i ** $exponent);
                $cumulative[] = $sum;
            }
            $this->zipf[$key] = array_map(static fn (float $c): float => $c / $sum, $cumulative);
        }
        $target = $this->float();
        foreach ($this->zipf[$key] as $i => $c) {
            if ($target < $c) {
                return $i;
            }
        }
        return $n - 1;
    }
}
