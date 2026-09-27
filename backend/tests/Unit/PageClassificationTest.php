<?php

declare(strict_types=1);

use App\Enums\PageClassification;

/*
|------------------------------------------------------------------------------
| PageClassification
|------------------------------------------------------------------------------
|
| The product's central claim is "this page has nothing pointing at it". The
| boundaries of that claim are worth pinning down precisely: an off-by-one here
| either invents orphans that are actually linked, or hides real ones.
|
*/

describe('classifying a page by its inbound link count', function (): void {
    it('calls a page with no inbound links an orphan', function (): void {
        expect(PageClassification::fromInboundCount(0, weakThreshold: 2))
            ->toBe(PageClassification::Orphan);
    });

    it('calls a page at or below the weak threshold weakly linked', function (int $count): void {
        expect(PageClassification::fromInboundCount($count, weakThreshold: 2))
            ->toBe(PageClassification::Weak);
    })->with([1, 2]);

    it('calls a page above the threshold linked', function (int $count): void {
        expect(PageClassification::fromInboundCount($count, weakThreshold: 2))
            ->toBe(PageClassification::Linked);
    })->with([3, 4, 50]);

    it('respects a configured threshold rather than hardcoding two', function (): void {
        expect(PageClassification::fromInboundCount(4, weakThreshold: 5))
            ->toBe(PageClassification::Weak)
            ->and(PageClassification::fromInboundCount(6, weakThreshold: 5))
            ->toBe(PageClassification::Linked);
    });

    it('treats a threshold of zero as leaving only orphans and linked pages', function (): void {
        expect(PageClassification::fromInboundCount(0, weakThreshold: 0))
            ->toBe(PageClassification::Orphan)
            ->and(PageClassification::fromInboundCount(1, weakThreshold: 0))
            ->toBe(PageClassification::Linked);
    });

    it('never reports a negative count as anything but an orphan', function (): void {
        expect(PageClassification::fromInboundCount(-1, weakThreshold: 2))
            ->toBe(PageClassification::Orphan);
    });
});

describe('presentation', function (): void {
    it('gives each classification a human label', function (): void {
        expect(PageClassification::Orphan->label())->toBe('Orphan')
            ->and(PageClassification::Weak->label())->toBe('Weakly linked')
            ->and(PageClassification::Linked->label())->toBe('Linked');
    });

    it('knows which classifications represent an opportunity to act', function (): void {
        expect(PageClassification::Orphan->needsAttention())->toBeTrue()
            ->and(PageClassification::Weak->needsAttention())->toBeTrue()
            ->and(PageClassification::Linked->needsAttention())->toBeFalse();
    });
});
