<?php

declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * Ranks a suggested link (spec 5.6).
 *
 * A purely similarity-ranked list would put the most obviously related pairs
 * first — which, on most sites, means the pages that are already thoroughly
 * linked to each other. The product exists to surface the opposite: pages
 * nothing points at. So the score trades a little topical closeness for a lot
 * of need.
 *
 *     priority = similarity x similarityWeight + scarcity x scarcityWeight
 *
 * Both weights are configuration, so the balance can be tuned per deployment
 * without touching this arithmetic.
 */
final class PriorityScore
{
    public static function calculate(
        float $similarity,
        int $targetInboundLinks,
        float $similarityWeight,
        float $scarcityWeight,
    ): float {
        // Cosine is defined on [-1, 1], but a negatively related page is not a
        // weak suggestion — it is not a suggestion, and letting it go negative
        // would drag the combined score below zero.
        $similarity = max(0.0, min(1.0, $similarity));

        $score = ($similarity * $similarityWeight)
            + (self::scarcity($targetInboundLinks) * $scarcityWeight);

        $total = $similarityWeight + $scarcityWeight;

        // Normalised by the weights so the result stays in [0, 1] whatever
        // weighting is configured, and scores remain comparable across projects.
        return $total <= 0.0 ? 0.0 : $score / $total;
    }

    /**
     * How badly a page needs inbound links, as a value in (0, 1].
     *
     * Decays as links accumulate: an orphan scores 1, one inbound link 0.5, two
     * 0.33. It never reaches zero, so a well-linked page is pushed down the
     * list rather than excluded from it.
     */
    public static function scarcity(int $inboundLinks): float
    {
        return 1.0 / (1.0 + (float) max(0, $inboundLinks));
    }
}
