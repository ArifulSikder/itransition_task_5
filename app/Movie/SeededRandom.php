<?php

namespace App\Movie;

/**
 * Deterministic 48-bit random number generator (same idea as java.util.Random).
 * The same seed always produces the same sequence on any machine.
 */
final class SeededRandom
{
    public const MASK = 281474976710655; // 2^48 - 1

    private const MULTIPLIER = 25214903917;
    private const ADDEND = 11;
    private const MAD_MUL = 1103515245;
    private const MAD_ADD = 12345;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = self::mod48($seed ^ self::MULTIPLIER);
    }

    /** Mix a user seed with a page number or movie index. */
    public static function mix(int $seed, int $salt): int
    {
        return self::mod48(self::mul48(self::MAD_MUL, $seed) + self::MAD_ADD * $salt);
    }

    public function nextFloat(): float
    {
        $this->seed = self::mod48(self::mul48(self::MULTIPLIER, $this->seed) + self::ADDEND);

        return ($this->seed >> 24) / 16777216.0; // 24 bits of the seed, divided by 2^24
    }

    public function nextInt(int $min, int $max): int
    {
        if ($max < $min) {
            throw new \InvalidArgumentException('max must be >= min');
        }

        return $min + (int) floor($this->nextFloat() * ($max - $min + 1));
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Cannot pick from an empty list.');
        }

        return $items[$this->nextInt(0, count($items) - 1)];
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function pickN(array $items, int $n): array
    {
        if ($n <= 0 || $items === []) {
            return [];
        }

        $copy = $items;
        $this->shuffle($copy);

        return array_slice($copy, 0, min($n, count($copy)));
    }

    /** @param list<mixed> $items */
    public function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; --$i) {
            $j = $this->nextInt(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    public function bool(float $probability): bool
    {
        return $this->nextFloat() < $probability;
    }

    /**
     * Whole number is guaranteed; the fraction is the chance of +1.
     * 3.7 → 3, or 4 with 70% probability.
     */
    public function probabilisticCount(float $average, int $max = 10): int
    {
        $average = max(0.0, min((float) $max, $average));
        $base = (int) floor($average);

        if ($this->bool($average - $base)) {
            ++$base;
        }

        return min($max, $base);
    }

    private static function mod48(int $value): int
    {
        return $value & self::MASK;
    }

    /** Low 48 bits of a * b, split so PHP 64-bit ints do not overflow. */
    private static function mul48(int $a, int $b): int
    {
        $a = self::mod48($a);
        $b = self::mod48($b);
        $low = ($a & 0xFFFFFF) * ($b & 0xFFFFFF);
        $mid = ($a & 0xFFFFFF) * ($b >> 24) + ($a >> 24) * ($b & 0xFFFFFF);

        return self::mod48($low + (($mid & 0xFFFFFF) << 24));
    }
}
