<?php

declare(strict_types=1);

use App\Services\Gemini\EmbeddingText;

/*
|------------------------------------------------------------------------------
| EmbeddingText
|------------------------------------------------------------------------------
|
| Builds what actually gets sent to the embedding model (spec 5.5): title, H1,
| and the first N words of clean content. The word budget is a config value
| because it is bounded by the model's token limit — gemini-embedding-001 tops
| out at 2,048 tokens, which ~1,500 words would overflow.
|
*/

describe('composition', function (): void {
    it('leads with the title and H1, then the content', function (): void {
        $text = EmbeddingText::build('Fleet Insurance', 'Fleet cover explained', 'Body copy here.', 1500);

        expect($text)->toBe("Fleet Insurance\nFleet cover explained\n\nBody copy here.");
    });

    it('omits a missing title', function (): void {
        expect(EmbeddingText::build(null, 'Only an H1', 'Body.', 1500))
            ->toBe("Only an H1\n\nBody.");
    });

    it('omits a missing H1', function (): void {
        expect(EmbeddingText::build('Only a title', null, 'Body.', 1500))
            ->toBe("Only a title\n\nBody.");
    });

    it('does not repeat the H1 when it merely restates the title', function (): void {
        // Common in WordPress themes, and repeating it skews the embedding
        // towards the heading and away from the body.
        expect(EmbeddingText::build('Fleet Insurance', 'Fleet Insurance', 'Body.', 1500))
            ->toBe("Fleet Insurance\n\nBody.");
    });

    it('ignores case and surrounding space when comparing title and H1', function (): void {
        expect(EmbeddingText::build('Fleet Insurance', '  fleet insurance ', 'Body.', 1500))
            ->toBe("Fleet Insurance\n\nBody.");
    });

    it('handles a page with no content at all', function (): void {
        expect(EmbeddingText::build('Just a title', null, null, 1500))->toBe('Just a title');
    });

    it('returns an empty string when there is nothing to embed', function (): void {
        expect(EmbeddingText::build(null, null, null, 1500))->toBe('');
    });
});

describe('the word budget', function (): void {
    it('truncates content to the configured number of words', function (): void {
        $content = implode(' ', array_map(static fn (int $i): string => "word{$i}", range(1, 100)));

        $text = EmbeddingText::build(null, null, $content, 10);

        expect(str_word_count($text))->toBe(10)
            ->and($text)->toStartWith('word1 ')
            ->and($text)->toEndWith('word10');
    });

    it('leaves content shorter than the budget intact', function (): void {
        expect(EmbeddingText::build(null, null, 'three little words', 100))
            ->toBe('three little words');
    });

    it('counts only content words, so a long title cannot crowd out the body', function (): void {
        $content = implode(' ', array_fill(0, 50, 'body'));

        $text = EmbeddingText::build('A Title', null, $content, 5);

        expect($text)->toBe("A Title\n\nbody body body body body");
    });
});

describe('cleanliness', function (): void {
    it('collapses runs of whitespace left by HTML extraction', function (): void {
        expect(EmbeddingText::build(null, null, "one   two\n\n\tthree", 1500))
            ->toBe('one two three');
    });

    it('trims each part', function (): void {
        expect(EmbeddingText::build('  Title  ', null, '  Body  ', 1500))
            ->toBe("Title\n\nBody");
    });
});
