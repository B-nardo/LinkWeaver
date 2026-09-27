<?php

declare(strict_types=1);

use App\Services\Analysis\SimilarityCalculator;

/*
|------------------------------------------------------------------------------
| SimilarityCalculator
|------------------------------------------------------------------------------
|
| Vectors are normalised once at write time, so the hot loop over every pair of
| pages is a plain dot product rather than a cosine with two magnitudes
| recomputed on each comparison. These tests pin down both, and the identity
| that makes the substitution safe.
|
*/

describe('normalisation', function (): void {
    it('scales a vector to unit length', function (): void {
        $unit = SimilarityCalculator::normalize([3.0, 4.0]);

        expect(SimilarityCalculator::magnitude($unit))->toEqualWithDelta(1.0, 1e-9)
            ->and($unit[0])->toEqualWithDelta(0.6, 1e-9)
            ->and($unit[1])->toEqualWithDelta(0.8, 1e-9);
    });

    it('leaves an already normalised vector alone', function (): void {
        $unit = SimilarityCalculator::normalize([1.0, 0.0, 0.0]);

        expect($unit)->toBe([1.0, 0.0, 0.0]);
    });

    it('returns a zero vector untouched rather than dividing by zero', function (): void {
        expect(SimilarityCalculator::normalize([0.0, 0.0, 0.0]))->toBe([0.0, 0.0, 0.0]);
    });

    it('preserves direction', function (): void {
        $a = SimilarityCalculator::normalize([2.0, 1.0]);
        $b = SimilarityCalculator::normalize([20.0, 10.0]);

        expect($a[0])->toEqualWithDelta($b[0], 1e-9)
            ->and($a[1])->toEqualWithDelta($b[1], 1e-9);
    });
});

describe('cosine similarity', function (): void {
    it('scores an identical direction as 1', function (): void {
        expect(SimilarityCalculator::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]))
            ->toEqualWithDelta(1.0, 1e-9);
    });

    it('scores the same direction at a different magnitude as 1', function (): void {
        expect(SimilarityCalculator::cosine([1.0, 2.0, 3.0], [10.0, 20.0, 30.0]))
            ->toEqualWithDelta(1.0, 1e-9);
    });

    it('scores an opposite direction as -1', function (): void {
        expect(SimilarityCalculator::cosine([1.0, 2.0], [-1.0, -2.0]))
            ->toEqualWithDelta(-1.0, 1e-9);
    });

    it('scores perpendicular vectors as 0', function (): void {
        expect(SimilarityCalculator::cosine([1.0, 0.0], [0.0, 1.0]))
            ->toEqualWithDelta(0.0, 1e-9);
    });

    it('treats a zero vector as unrelated rather than failing', function (): void {
        expect(SimilarityCalculator::cosine([0.0, 0.0], [1.0, 1.0]))->toBe(0.0);
    });

    it('refuses to compare vectors of different lengths', function (): void {
        SimilarityCalculator::cosine([1.0, 2.0], [1.0, 2.0, 3.0]);
    })->throws(InvalidArgumentException::class);
});

describe('the dot-product shortcut', function (): void {
    it('matches cosine once both vectors are normalised', function (array $a, array $b): void {
        $expected = SimilarityCalculator::cosine($a, $b);

        $actual = SimilarityCalculator::dot(
            SimilarityCalculator::normalize($a),
            SimilarityCalculator::normalize($b),
        );

        expect($actual)->toEqualWithDelta($expected, 1e-9);
    })->with([
        'identical' => [[1.0, 2.0, 3.0], [1.0, 2.0, 3.0]],
        'related' => [[0.4, 0.9, 0.1], [0.5, 0.8, 0.2]],
        'unrelated' => [[1.0, 0.0, 0.0], [0.0, 0.0, 1.0]],
        'opposed' => [[1.0, 1.0], [-1.0, -1.0]],
        'scaled' => [[2.0, 4.0, 6.0], [1.0, 2.0, 3.0]],
    ]);
});

describe('numerical safety', function (): void {
    it('never returns a value outside the range cosine is defined on', function (): void {
        // Floating point drift can push an identical pair a hair above 1.0,
        // which would then leak into a priority score greater than one.
        $vector = SimilarityCalculator::normalize(array_fill(0, 768, 0.5));

        expect(SimilarityCalculator::dot($vector, $vector))->toBeLessThanOrEqual(1.0)
            ->toBeGreaterThanOrEqual(-1.0);
    });
});
