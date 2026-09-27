<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a suggested link, from candidate to applied.
 *
 * `Pending` covers both a phase 3 candidate with no anchor yet and a phase 4
 * suggestion awaiting review; what distinguishes them is whether `anchor_text`
 * is null, not a separate state.
 */
enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Applied => 'Applied to WordPress',
            self::Failed => 'Failed to apply',
        };
    }
}
