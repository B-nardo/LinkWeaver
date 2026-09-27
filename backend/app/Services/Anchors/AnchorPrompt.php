<?php

declare(strict_types=1);

namespace App\Services\Anchors;

/**
 * Builds the request sent to the text model for one candidate link.
 *
 * The prompt is written as an extraction task, not a writing task: the model is
 * told to quote from text it has been given, and told explicitly that inventing
 * a phrase is worse than declining. That framing measurably reduces the
 * paraphrasing that `AnchorValidator` would otherwise have to reject — and
 * every rejection is a wasted request against a rate-limited quota.
 */
final class AnchorPrompt
{
    public static function build(
        string $sourceContent,
        ?string $targetTitle,
        ?string $targetDescription,
        int $contextWords,
        int $minWords,
        int $maxWords,
    ): string {
        $content = self::firstWords($sourceContent, $contextWords);
        $title = trim((string) $targetTitle);
        $description = trim((string) $targetDescription);

        $target = $title === '' ? 'Untitled page' : $title;

        if ($description !== '') {
            $target .= "\nDescription: {$description}";
        }

        return <<<PROMPT
            You are helping add an internal link between two pages of the same website.

            SOURCE PAGE CONTENT:
            \"\"\"
            {$content}
            \"\"\"

            TARGET PAGE:
            {$target}

            Choose a phrase from the SOURCE PAGE CONTENT that would work as the clickable
            anchor text for a link to the TARGET PAGE.

            Rules:
            - The phrase must be copied EXACTLY from the source content above, character for
              character. Do not reword, correct, shorten or paraphrase it.
            - It must be between {$minWords} and {$maxWords} words.
            - It must read naturally as a link to the target page.
            - Do not choose generic phrases such as "click here" or "read more".

            If no phrase in the source content is a good fit, return null for the anchor.
            Returning null is the correct answer when nothing fits; inventing a phrase that
            is not in the source content is always wrong.

            Respond with JSON: {"anchor": "the exact phrase", "sentence": "the full sentence containing it"}
            or {"anchor": null, "sentence": null}
            PROMPT;
    }

    /**
     * Gemini's OpenAPI-flavoured schema. Upper-case type names and an explicit
     * `nullable` flag; JSON-Schema syntax is rejected by the API.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'anchor' => ['type' => 'STRING', 'nullable' => true],
                'sentence' => ['type' => 'STRING', 'nullable' => true],
            ],
            'required' => ['anchor'],
        ];
    }

    private static function firstWords(string $content, int $limit): string
    {
        $words = preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, max(1, $limit)));
    }
}
