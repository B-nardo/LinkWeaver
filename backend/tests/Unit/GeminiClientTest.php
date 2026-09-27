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
