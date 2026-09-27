<?php

declare(strict_types=1);

namespace App\Services\Gemini;

/**
 * Builds the text sent to the embedding model for one page (spec 5.5).
 *
 * Takes plain values rather than a Page model so it stays a pure function: the
 * decision about what a page "is", topically, is worth being able to test
 * without a database.
 *
 * The word budget is bounded by the model's token limit, which is why it is a
 * configured value and not a constant — gemini-embedding-001 accepts 2,048
 * tokens, which the spec's ~1,500 words would overflow, while
 * gemini-embedding-2 accepts 8,192.
 */
final class EmbeddingText
{
    public static function build(
        ?string $title,
        ?string $h1,
        ?string $content,
        int $wordLimit,
    ): string {
        $title = self::clean($title);
        $h1 = self::clean($h1);
        $content = self::clean($content);

        $heading = [];

        if ($title !== '') {
            $heading[] = $title;
        }

        // WordPress themes routinely render the title again as the H1.
        // Including both would weight the embedding towards the heading and
        // away from the body that actually distinguishes the page.
        if ($h1 !== '' && mb_strtolower($h1) !== mb_strtolower($title)) {
            $heading[] = $h1;
        }

        $parts = [];

        if ($heading !== []) {
            $parts[] = implode("\n", $heading);
        }

        $body = self::firstWords($content, $wordLimit);

        if ($body !== '') {
            $parts[] = $body;
        }

        return implode("\n\n", $parts);
    }

    private static function firstWords(string $content, int $limit): string
    {
        if ($content === '' || $limit <= 0) {
            return '';
        }

        $words = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, $limit));
    }

    private static function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Extracted content arrives with the whitespace of the original markup
        // still in it; runs of it carry no meaning and cost tokens.
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
