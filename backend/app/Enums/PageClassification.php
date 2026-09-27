<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How well connected a page is, judged only by inbound in-content links from
 * other pages in the same project.
 *
 * This is the product's central claim, so the rules it encodes are deliberately
 * narrow: navigation and footer links are excluded before a count ever reaches
 * here, and a page cannot rescue itself by linking to itself.
 */
enum PageClassification: string
{
    case Orphan = 'orphan';
    case Weak = 'weak';
    case Linked = 'linked';

    public static function fromInboundCount(int $count, int $weakThreshold): self
    {
        if ($count <= 0) {
            return self::Orphan;
        }

        return $count <= $weakThreshold ? self::Weak : self::Linked;
    }

    public function label(): string
    {
        return match ($this) {
            self::Orphan => 'Orphan',
            self::Weak => 'Weakly linked',
            self::Linked => 'Linked',
        };
    }

    /**
     * Whether this page is worth surfacing as an opportunity. Drives the
     * default filters and the accent colour in the interface.
     */
    public function needsAttention(): bool
    {
        return $this !== self::Linked;
    }
}
