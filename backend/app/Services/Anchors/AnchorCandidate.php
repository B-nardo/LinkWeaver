<?php

declare(strict_types=1);

namespace App\Services\Anchors;

/**
 * The outcome of validating one piece of model output.
 *
 * Three states, kept distinct because they mean different things operationally:
 * accepted, declined (the model correctly said no suitable anchor exists), and
 * rejected (the model produced something unusable). Only the last is worth
 * investigating.
 */
final readonly class AnchorCandidate
{
    private function __construct(
        public ?string $anchor,
        public ?string $contextSentence,
        public ?string $reason,
        public bool $declined,
    ) {}

    public static function accepted(string $anchor, ?string $contextSentence): self
    {
        return new self($anchor, $contextSentence, null, false);
    }

    /**
     * The model looked and found nothing suitable. Expected and unremarkable:
     * not every pair of related pages has a phrase worth linking.
     */
    public static function declined(): self
    {
        return new self(null, null, 'The model found no suitable anchor on the source page.', true);
    }

    public static function rejected(string $reason): self
    {
        return new self(null, null, $reason, false);
    }

    public function isValid(): bool
    {
        return $this->anchor !== null;
    }
}
