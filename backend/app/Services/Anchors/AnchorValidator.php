<?php

declare(strict_types=1);

namespace App\Services\Anchors;

/**
 * Decides whether a model-suggested anchor can be trusted.
 *
 * Spec 5.7: "The model is useful but untrusted. Never store unvalidated model
 * output as a suggestion." This class is what earns that sentence.
 *
 * The failure mode that matters is not nonsense — nonsense is obvious. It is
 * the plausible paraphrase: asked to quote a phrase from a page, a language
 * model will often return something close but not identical. That produces a
 * suggestion nobody can apply, because the words are not on the page to be
 * linked, and it is indistinguishable from a good suggestion until someone
 * tries to use it.
 *
 * So every check is exact, and every rejection carries a reason.
 */
final class AnchorValidator
{
    public function __construct(
        private readonly int $minWords,
        private readonly int $maxWords,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            minWords: (int) config('linkweaver.anchors.min_words'),
            maxWords: (int) config('linkweaver.anchors.max_words'),
        );
    }

    /**
     * @param  mixed  $payload  Decoded model output; trusted to be nothing at all.
     * @param  list<string>  $existingAnchors  Anchor text of links already on the source page.
     * @param  list<string>  $headings  Headings in the source page's main content.
     */
    public function validate(
        mixed $payload,
        string $content,
        array $existingAnchors,
        array $headings,
    ): AnchorCandidate {
        if (! is_array($payload) || array_is_list($payload)) {
            return AnchorCandidate::rejected('The model did not return a JSON object.');
        }

        if (! array_key_exists('anchor', $payload)) {
            return AnchorCandidate::rejected('The model response contained no "anchor" key.');
        }

        $anchor = $payload['anchor'];

        if ($anchor === null) {
            return AnchorCandidate::declined();
        }

        if (! is_string($anchor)) {
            return AnchorCandidate::rejected('The "anchor" value was not a string.');
        }

        $anchor = $this->tidy($anchor);

        if ($anchor === '') {
            return AnchorCandidate::declined();
        }

        $wordCount = count(preg_split('/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($wordCount < $this->minWords || $wordCount > $this->maxWords) {
            $unit = $wordCount === 1 ? 'word' : 'words';

            return AnchorCandidate::rejected(
                "The anchor is {$wordCount} {$unit}; it must be between {$this->minWords} "
                ."and {$this->maxWords} words."
            );
        }

        // The load-bearing check. Everything else is a refinement of it.
        if (! $this->appearsIn($anchor, $content)) {
            return AnchorCandidate::rejected(
                'The anchor does not appear verbatim in the source page content.'
            );
        }

        foreach ($existingAnchors as $existing) {
            if ($this->appearsIn($anchor, $existing)) {
                return AnchorCandidate::rejected(
                    'The anchor is already part of a link on the source page.'
                );
            }
        }

        foreach ($headings as $heading) {
            if ($this->appearsIn($anchor, $heading)) {
                return AnchorCandidate::rejected('The anchor is inside a heading.');
            }
        }

        return AnchorCandidate::accepted(
            $anchor,
            $this->contextFor($anchor, $payload['sentence'] ?? null, $content),
        );
    }

    /**
     * Whether `$needle` occurs in `$haystack` as whole words.
     *
     * Case and whitespace are normalised, because those differences are
     * cosmetic and would reject otherwise usable anchors. Word boundaries are
     * enforced so "property insuran" does not match inside "property
     * insurance", which would produce an anchor that cannot be highlighted.
     */
    private function appearsIn(string $needle, string $haystack): bool
    {
        $needle = $this->normalize($needle);
        $haystack = $this->normalize($haystack);

        if ($needle === '' || $haystack === '') {
            return false;
        }

        // Word-boundary assertions are written explicitly rather than with \b,
        // which behaves unhelpfully around apostrophes and accented letters.
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return preg_match($pattern, $haystack) === 1;
    }

    /**
     * The sentence the anchor sits in, for the review screen to highlight.
     *
     * The model is asked for this, but its answer is only used when it actually
     * contains the anchor; otherwise the sentence is recovered from the content
     * directly, which is both more reliable and free.
     */
    private function contextFor(string $anchor, mixed $offered, string $content): ?string
    {
        if (is_string($offered) && $this->appearsIn($anchor, $offered)) {
            return $this->tidy($offered);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $this->tidy($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($sentences as $sentence) {
            if ($this->appearsIn($anchor, $sentence)) {
                return $sentence;
            }
        }

        return null;
    }

    /**
     * Collapses whitespace and strips the quotes and trailing punctuation
     * models habitually wrap around a quoted phrase.
     */
    private function tidy(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value, " \t\n\r\0\x0B\"'“”‘’`.,;:!?");
    }

    private function normalize(string $value): string
    {
        return mb_strtolower($this->tidy($value));
    }
}
