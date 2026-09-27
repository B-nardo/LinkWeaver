<?php

declare(strict_types=1);

use App\Services\Anchors\AnchorCandidate;
use App\Services\Anchors\AnchorValidator;

/*
|------------------------------------------------------------------------------
| AnchorValidator
|------------------------------------------------------------------------------
|
| Spec 5.7: "The model is useful but untrusted. Never store unvalidated model
| output as a suggestion."
|
| This is the class that earns that sentence. A language model asked to quote a
| phrase from a page will, often enough, paraphrase it, reword it slightly, or
| invent something plausible. Any of those produce a suggestion that cannot be
| applied, because the anchor text does not exist on the page to be linked.
|
| Every rejection carries a reason, so failures are diagnosable rather than
| silently discarded.
|
*/

const SOURCE_CONTENT = 'Commercial property insurance protects the buildings and contents a '
    .'business depends on. Setting the sum insured too low triggers the average clause, so a '
    .'formal valuation every three years is worth the cost. Many firms also carry business '
    .'interruption cover alongside it.';

function anchorValidator(int $min = 2, int $max = 6): AnchorValidator
{
    return new AnchorValidator(minWords: $min, maxWords: $max);
}

/**
 * @param  list<string>  $existingAnchors
 * @param  list<string>  $headings
 */
function validate(
    mixed $payload,
    string $content = SOURCE_CONTENT,
    array $existingAnchors = [],
    array $headings = [],
): AnchorCandidate {
    return anchorValidator()->validate($payload, $content, $existingAnchors, $headings);
}

describe('accepting a good anchor', function (): void {
    it('accepts a phrase that appears verbatim in the content', function (): void {
        $result = validate(['anchor' => 'triggers the average clause', 'sentence' => null]);

        expect($result->isValid())->toBeTrue()
            ->and($result->anchor)->toBe('triggers the average clause');
    });

    it('keeps the sentence containing the anchor', function (): void {
        $result = validate([
            'anchor' => 'triggers the average clause',
            'sentence' => 'Setting the sum insured too low triggers the average clause, so a formal valuation every three years is worth the cost.',
        ]);

        expect($result->contextSentence)->toContain('triggers the average clause');
    });

    it('recovers the sentence itself when the model omits it', function (): void {
        $result = validate(['anchor' => 'triggers the average clause']);

        // The review screen highlights the anchor inside its sentence, so a
        // missing sentence is worth reconstructing rather than discarding.
        expect($result->isValid())->toBeTrue()
            ->and($result->contextSentence)->toContain('Setting the sum insured too low');
    });

    it('accepts an anchor at either word-count boundary', function (string $anchor): void {
        $content = 'We discuss fleet insurance and also cover employers liability for small firms today.';

        expect(anchorValidator()->validate(['anchor' => $anchor], $content, [], [])->isValid())->toBeTrue();
    })->with([
        'two words' => 'fleet insurance',
        'six words' => 'employers liability for small firms today',
    ]);
});

describe('the model declining', function (): void {
    it('treats a null anchor as a legitimate decline, not an error', function (): void {
        $result = validate(['anchor' => null]);

        expect($result->isValid())->toBeFalse()
            ->and($result->declined)->toBeTrue();
    });

    it('treats an empty anchor as a decline', function (): void {
        expect(validate(['anchor' => '  '])->declined)->toBeTrue();
    });
});

describe('rejecting invented text', function (): void {
    it('rejects a paraphrase, however reasonable', function (): void {
        // This is the failure mode that matters: plausible, useful-sounding,
        // and impossible to apply because the words are not on the page.
        $result = validate(['anchor' => 'sets off the averaging clause']);

        expect($result->isValid())->toBeFalse()
            ->and($result->reason)->toContain('verbatim');
    });

    it('rejects an anchor that is only partly present', function (): void {
        expect(validate(['anchor' => 'average clause penalty'])->isValid())->toBeFalse();
    });

    it('rejects text invented wholesale', function (): void {
        expect(validate(['anchor' => 'click here for details'])->isValid())->toBeFalse();
    });
});

describe('matching tolerance', function (): void {
    it('ignores case differences', function (): void {
        expect(validate(['anchor' => 'Triggers The Average Clause'])->isValid())->toBeTrue();
    });

    it('ignores differences in whitespace', function (): void {
        expect(validate(['anchor' => "triggers   the\naverage clause"])->isValid())->toBeTrue();
    });

    it('trims surrounding punctuation the model added', function (): void {
        expect(validate(['anchor' => '"triggers the average clause"'])->isValid())->toBeTrue();
    });

    it('does not match across a word boundary', function (): void {
        // "insuran" appears inside "insurance", but is not a word.
        expect(validate(['anchor' => 'property insuran'])->isValid())->toBeFalse();
    });
});

describe('word count', function (): void {
    it('rejects an anchor that is too short', function (): void {
        $result = validate(['anchor' => 'insurance']);

        expect($result->isValid())->toBeFalse()
            ->and($result->reason)->toContain('words');
    });

    it('rejects an anchor that is too long', function (): void {
        $result = validate([
            'anchor' => 'Commercial property insurance protects the buildings and contents',
        ]);

        expect($result->isValid())->toBeFalse()
            ->and($result->reason)->toContain('words');
    });

    it('honours a configured range', function (): void {
        $result = anchorValidator(min: 1, max: 2)->validate(['anchor' => 'insurance'], SOURCE_CONTENT, [], []);

        expect($result->isValid())->toBeTrue();
    });
});

describe('avoiding text that is already linked', function (): void {
    it('rejects an anchor identical to an existing link on the page', function (): void {
        $result = validate(
            ['anchor' => 'business interruption cover'],
            existingAnchors: ['business interruption cover'],
        );

        expect($result->isValid())->toBeFalse()
            ->and($result->reason)->toContain('already');
    });

    it('rejects an anchor contained inside an existing link', function (): void {
        $result = validate(
            ['anchor' => 'interruption cover'],
            existingAnchors: ['business interruption cover'],
        );

        expect($result->isValid())->toBeFalse();
    });

    it('ignores case when comparing with existing links', function (): void {
        $result = validate(
            ['anchor' => 'business interruption cover'],
            existingAnchors: ['Business Interruption Cover'],
        );

        expect($result->isValid())->toBeFalse();
    });

    it('allows an anchor that merely shares a word with an existing link', function (): void {
        $result = validate(
            ['anchor' => 'triggers the average clause'],
            existingAnchors: ['business interruption cover'],
        );

        expect($result->isValid())->toBeTrue();
    });
});

describe('avoiding headings', function (): void {
    it('rejects an anchor that sits inside a heading', function (): void {
        $result = validate(
            ['anchor' => 'the average clause'],
            headings: ['How the average clause works'],
        );

        expect($result->isValid())->toBeFalse()
            ->and($result->reason)->toContain('heading');
    });

    it('rejects an anchor equal to a whole heading', function (): void {
        $result = validate(
            ['anchor' => 'triggers the average clause'],
            headings: ['Triggers the average clause'],
        );

        expect($result->isValid())->toBeFalse();
    });

    it('allows an anchor unrelated to any heading', function (): void {
        $result = validate(
            ['anchor' => 'triggers the average clause'],
            headings: ['Why valuations matter'],
        );

        expect($result->isValid())->toBeTrue();
    });
});

describe('malformed model output', function (): void {
    it('rejects a payload that is not an object', function (mixed $payload): void {
        expect(validate($payload)->isValid())->toBeFalse();
    })->with([
        'a string' => 'triggers the average clause',
        'a list' => [['triggers the average clause']],
        'null' => null,
        'a number' => 42,
    ]);

    it('rejects an object with no anchor key', function (): void {
        $result = validate(['sentence' => 'Some sentence.']);

        expect($result->isValid())->toBeFalse()
            ->and($result->reason)->toContain('anchor');
    });

    it('rejects a non-string anchor', function (): void {
        expect(validate(['anchor' => ['a', 'b']])->isValid())->toBeFalse();
    });

    it('never throws, whatever it is handed', function (mixed $payload): void {
        expect(anchorValidator()->validate($payload, '', [], []))->toBeInstanceOf(AnchorCandidate::class);
    })->with([
        // Wrapped one level deeper: an associative array at the top level is
        // read by Pest as a list of named parameters, not as one argument.
        'empty content' => [['anchor' => 'anything at all']],
        'null' => [null],
        'nested' => [['anchor' => ['deep' => ['deeper' => 'x']]]],
    ]);
});

describe('reporting', function (): void {
    it('always explains why it rejected something', function (): void {
        $result = validate(['anchor' => 'invented phrase entirely']);

        expect($result->reason)->not->toBeNull()
            ->and($result->reason)->not->toBe('');
    });

    it('gives no reason when it accepts', function (): void {
        expect(validate(['anchor' => 'triggers the average clause'])->reason)->toBeNull();
    });
});
