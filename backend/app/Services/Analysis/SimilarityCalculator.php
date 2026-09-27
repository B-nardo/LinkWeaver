<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use InvalidArgumentException;

/**
 * Cosine similarity over embedding vectors.
 *
 * Vectors are normalised once when they are stored, so comparing a project's
 * pages reduces to a dot product. That matters because the comparison is
 * all-pairs: at the 200-page cap that is ~20,000 comparisons of 768-element
 * vectors, and recomputing two magnitudes inside that loop would trebleÂ the
 * arithmetic for no gain.
 *
 * Static and free of state on purpose — it is arithmetic, not a collaborator.
 */
final class SimilarityCalculator
{
    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $magnitude = self::magnitude($vector);

        // A zero vector has no direction to preserve. Returning it unchanged
        // keeps it at similarity 0 against everything, which is the honest
        // answer, rather than producing NAN that poisons every comparison.
        if ($magnitude === 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => $value / $magnitude, $vector);
    }

    /**
     * @param  list<float>  $vector
     */
    public static function magnitude(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }

    /**
     * Similarity between two raw vectors of any magnitude.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        self::assertSameLength($a, $b);

        $magnitudeA = self::magnitude($a);
        $magnitudeB = self::magnitude($b);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return self::clamp(self::rawDot($a, $b) / ($magnitudeA * $magnitudeB));
    }

    /**
     * Similarity between two vectors that are already unit length.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        self::assertSameLength($a, $b);

        return self::clamp(self::rawDot($a, $b));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function rawDot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * $b[$index];
        }

        return $sum;
    }

    /**
     * Floating-point drift can push an identical pair a hair beyond 1.0, which
     * would then flow into a priority score above one and make the ranking read
     * as if something scored better than a perfect match.
     */
    private static function clamp(float $value): float
    {
        return max(-1.0, min(1.0, $value));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function assertSameLength(array $a, array $b): void
    {
        if (count($a) !== count($b)) {
            throw new InvalidArgumentException(
                'Cannot compare embeddings of different dimensionality ('
                .count($a).' and '.count($b).'). This usually means the embedding '
                .'model or its output_dimensionality changed without the cached '
                .'vectors being regenerated.'
            );
        }
    }
}
