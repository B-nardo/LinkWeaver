<?php

declare(strict_types=1);

use App\Services\Gemini\Exceptions\GeminiConfigurationException;
use App\Services\Gemini\Exceptions\GeminiRequestException;
use App\Services\Gemini\Exceptions\GeminiTransientException;
use App\Services\Gemini\GeminiClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;

/*
|------------------------------------------------------------------------------
| GeminiClient
|------------------------------------------------------------------------------
|
| Spec 11: no test may touch the real Gemini API. Every response here is faked.
|
| The request shape was taken from Google's published API reference rather than
| from memory, per spec 2: batchEmbedContents wants a `requests` array in which
| every entry repeats the model, and that model must match the one in the URL.
|
*/

const TEST_MODEL = 'gemini-embedding-2';

function geminiClient(HttpFactory $http, string $apiKey = 'test-key'): GeminiClient
{
    return new GeminiClient(
        http: $http,
        apiKey: $apiKey,
        baseUrl: 'https://generativelanguage.googleapis.com',
        timeout: 30,
    );
}

/**
 * @param  list<list<float>>  $vectors
 */
function embeddingResponse(array $vectors): array
{
    return [
        'embeddings' => array_map(static fn (array $values): array => ['values' => $values], $vectors),
        'usageMetadata' => ['promptTokenCount' => 42],
    ];
}

describe('batch embedding requests', function (): void {
    it('posts to the batch endpoint for the configured model', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1, 0.2]]))]);

        geminiClient($http)->embedBatch(['hello'], TEST_MODEL, 768);

        $http->assertSent(fn (Request $request): bool => $request->url() ===
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents');
    });

    it('repeats the model inside every request entry, as the API requires', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1], [0.2]]))]);

        geminiClient($http)->embedBatch(['one', 'two'], TEST_MODEL, 768);

        $http->assertSent(function (Request $request): bool {
            $requests = $request->data()['requests'];

            return count($requests) === 2
                && $requests[0]['model'] === 'models/gemini-embedding-2'
                && $requests[1]['model'] === 'models/gemini-embedding-2';
        });
    });

    it('sends each text as a content part', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1], [0.2]]))]);

        geminiClient($http)->embedBatch(['first text', 'second text'], TEST_MODEL, 768);

        $http->assertSent(function (Request $request): bool {
            $requests = $request->data()['requests'];

            return $requests[0]['content']['parts'][0]['text'] === 'first text'
                && $requests[1]['content']['parts'][0]['text'] === 'second text';
        });
    });

    it('asks for the configured dimensionality', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1]]))]);

        geminiClient($http)->embedBatch(['hello'], TEST_MODEL, 768);

        $http->assertSent(fn (Request $request): bool => $request->data()['requests'][0]['outputDimensionality'] === 768);
    });

    it('authenticates with a header rather than a query parameter', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1]]))]);

        geminiClient($http, 'secret-key')->embedBatch(['hello'], TEST_MODEL, 768);

        // A key in the URL leaks into logs, proxies and error reports.
        $http->assertSent(fn (Request $request): bool => $request->hasHeader('x-goog-api-key', 'secret-key')
            && ! str_contains($request->url(), 'secret-key'));
    });

    it('makes no request at all for an empty batch', function (): void {
        $http = new HttpFactory;
        $http->fake();

        expect(geminiClient($http)->embedBatch([], TEST_MODEL, 768))->toBe([]);

        $http->assertNothingSent();
    });
});

describe('reading the response', function (): void {
    it('returns one vector per input, in order', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]]))]);

        expect(geminiClient($http)->embedBatch(['a', 'b', 'c'], TEST_MODEL, 768))
            ->toBe([[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]]);
    });

    it('rejects a response with the wrong number of embeddings', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(embeddingResponse([[0.1]]))]);

        // Silently accepting this would pair vectors with the wrong pages,
        // which is worse than failing: every similarity would be nonsense.
        geminiClient($http)->embedBatch(['a', 'b'], TEST_MODEL, 768);
    })->throws(GeminiRequestException::class);

    it('rejects a response with no embeddings key', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(['unexpected' => true])]);

        geminiClient($http)->embedBatch(['a'], TEST_MODEL, 768);
    })->throws(GeminiRequestException::class);
});

describe('failure handling', function (): void {
    it('treats a rate limit as transient, so the job can back off and retry', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        geminiClient($http)->embedBatch(['a'], TEST_MODEL, 768);
    })->throws(GeminiTransientException::class);

    it('treats a server error as transient', function (int $status): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response('upstream down', $status)]);

        geminiClient($http)->embedBatch(['a'], TEST_MODEL, 768);
    })->with([500, 502, 503])->throws(GeminiTransientException::class);

    it('treats a client error as permanent, since retrying cannot fix it', function (int $status): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(['error' => ['message' => 'Bad request']], $status)]);

        geminiClient($http)->embedBatch(['a'], TEST_MODEL, 768);
    })->with([400, 401, 403, 404])->throws(GeminiRequestException::class);

    it('surfaces the API error message so failures are diagnosable', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(['error' => ['message' => 'API key not valid']], 400)]);

        expect(fn () => geminiClient($http)->embedBatch(['a'], TEST_MODEL, 768))
            ->toThrow(GeminiRequestException::class, 'API key not valid');
    });
});

describe('configuration', function (): void {
    it('refuses to run without an API key', function (): void {
        $http = new HttpFactory;
        $http->fake();

        geminiClient($http, '')->embedBatch(['a'], TEST_MODEL, 768);
    })->throws(GeminiConfigurationException::class);

    it('refuses to run without a model name', function (): void {
        $http = new HttpFactory;
        $http->fake();

        geminiClient($http)->embedBatch(['a'], '', 768);
    })->throws(GeminiConfigurationException::class);

    it('names the missing setting in the message', function (): void {
        $http = new HttpFactory;
        $http->fake();

        expect(fn () => geminiClient($http, '')->embedBatch(['a'], TEST_MODEL, 768))
            ->toThrow(GeminiConfigurationException::class, 'GEMINI_API_KEY');
    });
});

/*
|------------------------------------------------------------------------------
| Text generation
|------------------------------------------------------------------------------
|
| Request shape confirmed against the live API, not the docs: the two Google
| doc pages disagree, and only `generationConfig.responseMimeType` plus a
| schema in Gemini's OpenAPI dialect (upper-case types, `nullable`) is actually
| accepted. JSON-Schema syntax returns HTTP 400.
|
*/

const TEXT_MODEL = 'gemini-3.5-flash-lite';

function textResponse(string $text, string $finishReason = 'STOP'): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => $text]]],
            'finishReason' => $finishReason,
        ]],
    ];
}

describe('generateJson', function (): void {
    it('posts to generateContent for the configured model', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('{"anchor":null}'))]);

        geminiClient($http)->generateJson('prompt', TEXT_MODEL);

        $http->assertSent(fn (Request $request): bool => $request->url() ===
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent');
    });

    it('asks for JSON back', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('{"anchor":null}'))]);

        geminiClient($http)->generateJson('prompt', TEXT_MODEL);

        $http->assertSent(fn (Request $request): bool => $request->data()['generationConfig']['responseMimeType'] === 'application/json');
    });

    it('sends a schema in the dialect the API actually accepts', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('{"anchor":null}'))]);

        $schema = ['type' => 'OBJECT', 'properties' => ['anchor' => ['type' => 'STRING', 'nullable' => true]]];

        geminiClient($http)->generateJson('prompt', TEXT_MODEL, $schema);

        $http->assertSent(fn (Request $request): bool => $request->data()['generationConfig']['responseSchema'] === $schema);
    });

    it('sends the prompt and generation settings', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('{"anchor":null}'))]);

        geminiClient($http)->generateJson('find me an anchor', TEXT_MODEL, [], 0.3, 250);

        $http->assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['contents'][0]['parts'][0]['text'] === 'find me an anchor'
                && $body['generationConfig']['temperature'] === 0.3
                && $body['generationConfig']['maxOutputTokens'] === 250;
        });
    });

    it('returns the decoded payload', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('{"anchor":"average clause","sentence":"A sentence."}'))]);

        expect(geminiClient($http)->generateJson('prompt', TEXT_MODEL))
            ->toBe(['anchor' => 'average clause', 'sentence' => 'A sentence.']);
    });

    it('tolerates a markdown fence around the JSON', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse("```json\n{\"anchor\":\"x y\"}\n```"))]);

        expect(geminiClient($http)->generateJson('prompt', TEXT_MODEL))->toBe(['anchor' => 'x y']);
    });

    it('returns null rather than throwing when the text is not JSON', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('I could not find a suitable anchor.'))]);

        // One unusable answer should cost one candidate, not the whole job.
        expect(geminiClient($http)->generateJson('prompt', TEXT_MODEL))->toBeNull();
    });

    it('returns null when the model was cut off mid-answer', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(textResponse('{"anchor":"trun', 'MAX_TOKENS'))]);

        expect(geminiClient($http)->generateJson('prompt', TEXT_MODEL))->toBeNull();
    });

    it('returns null when safety filters removed the answer', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(['candidates' => [['finishReason' => 'SAFETY']]])]);

        expect(geminiClient($http)->generateJson('prompt', TEXT_MODEL))->toBeNull();
    });

    it('still treats transport failures as it does elsewhere', function (): void {
        $http = new HttpFactory;
        $http->fake(['*' => $http::response(['error' => ['message' => 'Quota exceeded']], 429)]);

        geminiClient($http)->generateJson('prompt', TEXT_MODEL);
    })->throws(GeminiTransientException::class);

    it('refuses to run without a text model configured', function (): void {
        $http = new HttpFactory;
        $http->fake();

        geminiClient($http)->generateJson('prompt', '');
    })->throws(GeminiConfigurationException::class);
});
