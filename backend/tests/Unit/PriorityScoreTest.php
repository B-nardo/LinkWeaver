<?php

declare(strict_types=1);

use App\Services\Analysis\PriorityScore;

/*
|------------------------------------------------------------------------------
| PriorityScore
|------------------------------------------------------------------------------
|
| Spec 5.6: combine similarity with how few inbound links the target has, so
| weak and orphan pages rise to the top.
|
| This is the ranking the whole review screen is sorted by, so the property
| that matters most is not any particular number — it is that a strongly
| related suggestion pointing at an already well-linked page must not outrank a
| slightly weaker one pointing at an orphan. That is the entire product thesis.
|
*/

const SIM_WEIGHT = 0.6;
const SCARCITY_WEIGHT = 0.4;

function score(float $similarity, int $inbound): float
{
    return PriorityScore::calculate($similarity, $inbound, SIM_WEIGHT, SCARCITY_WEIGHT);
}

describe('scarcity', function (): void {
    it('gives an orphan the maximum scarcity contribution', function (): void {
        expect(PriorityScore::scarcity(0))->toBe(1.0);
    });

    it('decays as the target accumulates inbound links', function (): void {
        expect(PriorityScore::scarcity(0))->toBeGreaterThan(PriorityScore::scarcity(1))
            ->and(PriorityScore::scarcity(1))->toBeGreaterThan(PriorityScore::scarcity(2))
            ->and(PriorityScore::scarcity(2))->toBeGreaterThan(PriorityScore::scarcity(10));
    });

    it('never reaches zero, so a well-linked page is deprioritised but not excluded', function (): void {
        expect(PriorityScore::scarcity(1000))->toBeGreaterThan(0.0);
    });

    it('treats a negative count as an orphan rather than going out of range', function (): void {
        expect(PriorityScore::scarcity(-5))->toBe(1.0);
    });
});

describe('the combined score', function (): void {
    it('is the weighted sum of similarity and scarcity', function (): void {
        // 0.8 * 0.6 + 1.0 * 0.4
        expect(score(0.8, 0))->toEqualWithDelta(0.88, 1e-9);
    });

    it('stays within zero and one', function (float $similarity, int $inbound): void {
        expect(score($similarity, $inbound))->toBeGreaterThanOrEqual(0.0)
            ->toBeLessThanOrEqual(1.0);
    })->with([
        [1.0, 0],
        [0.0, 0],
        [1.0, 1000],
        [0.75, 3],
    ]);

    it('treats a negative similarity as no relationship', function (): void {
        // Cosine is defined on [-1, 1], but a negatively related page is not a
        // weaker suggestion — it is not a suggestion at all.
        expect(score(-0.5, 0))->toEqualWithDelta(score(0.0, 0), 1e-9);
    });
});

describe('the ranking property that matters', function (): void {
    it('ranks a slightly weaker match to an orphan above a stronger match to a well-linked page', function (): void {
        $toOrphan = score(0.78, 0);
        $toPopular = score(0.95, 12);

        expect($toOrphan)->toBeGreaterThan($toPopular);
    });

    it('still prefers the better match when both targets are equally linked', function (): void {
        expect(score(0.9, 2))->toBeGreaterThan(score(0.8, 2));
    });

    it('still prefers the needier target when both matches are equally similar', function (): void {
        expect(score(0.85, 0))->toBeGreaterThan(score(0.85, 5));
    });

    it('lets the weighting be tuned to ignore scarcity entirely', function (): void {
        $similarityOnly = fn (float $s, int $i): float => PriorityScore::calculate($s, $i, 1.0, 0.0);

        expect($similarityOnly(0.95, 12))->toBeGreaterThan($similarityOnly(0.78, 0));
    });
});
