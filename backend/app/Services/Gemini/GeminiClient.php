<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Services\Gemini\Exceptions\GeminiConfigurationException;
use App\Services\Gemini\Exceptions\GeminiRequestException;
use App\Services\Gemini\Exceptions\GeminiTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Talks to the Gemini API.
 *
 * The request shape follows Google's published reference rather than anything
 * remembered: `batchEmbedContents` takes a `requests` array in which every
 * entry repeats the model, and that model must match the one in the URL.
 *
 * The client's one editorial decision is how it classifies failure. The queue
 * needs to know whether an error is worth retrying — a rate limit is, a
 * rejected API key never will be — so transport concerns are translated into
 * exactly two kinds of exception and nothing else leaks out.
 */
final class GeminiClient
{
    private const string API_VERSION = 'v1beta';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(HttpFactory $http): self
    {
        return new self(
            http: $http,
            apiKey: (string) config('linkweaver.gemini.api_key'),
            baseUrl: rtrim((string) config('linkweaver.gemini.base_url'), '/'),
            timeout: (int) config('linkweaver.gemini.timeout'),
        );
    }

    /**
     * Embeds several texts in one call.
     *
     * Chunking is the caller's concern: this sends exactly what it is given, so
     * the batch size stays a single configurable number in the job rather than
     * a rule hidden in two places.
     *
     * @param  list<string>  $texts
     * @return list<list<float>> one vector per input, in the same order
     *
     * @throws GeminiConfigurationException
     * @throws GeminiRequestException
     * @throws GeminiTransientException
     */
    public function embedBatch(array $texts, string $model, int $dimensions): array
    {
        if ($texts === []) {
            return [];
        }

        $this->assertConfigured($model);

        $qualifiedModel = 'models/'.$model;

        $response = $this->post(
            "{$this->baseUrl}/".self::API_VERSION."/{$qualifiedModel}:batchEmbedContents",
            [
                'requests' => array_map(static fn (string $text): array => [
                    'model' => $qualifiedModel,
                    'content' => ['parts' => [['text' => $text]]],
                    'outputDimensionality' => $dimensions,
                ], $texts),
            ]
        );

        return $this->readEmbeddings($response, count($texts));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $url, array $payload): Response
    {
        try {
            $response = $this->http
                ->withHeaders([
                    // In a header rather than a query parameter: a key in the
                    // URL ends up in logs, proxies and error reports.
                    'x-goog-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            // A refused connection or timeout is worth another attempt.
            throw GeminiTransientException::connectionFailed($e->getMessage());
        }

        return $this->assertSuccessful($response);
    }

    private function assertSuccessful(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($status === 429) {
            throw GeminiTransientException::rateLimited($this->errorMessage($response));
        }

        if ($status >= 500) {
            throw GeminiTransientException::unavailable($status);
        }

        throw GeminiRequestException::rejected($status, $this->errorMessage($response));
    }

    /**
     * @return list<list<float>>
     */
    private function readEmbeddings(Response $response, int $expected): array
    {
        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings)) {
            throw GeminiRequestException::malformedResponse('no "embeddings" array was present.');
        }

        if (count($embeddings) !== $expected) {
            throw GeminiRequestException::countMismatch($expected, count($embeddings));
        }

        $vectors = [];

        foreach ($embeddings as $index => $embedding) {
            $values = $embedding['values'] ?? null;

            if (! is_array($values) || $values === []) {
                throw GeminiRequestException::malformedResponse(
                    "embedding at position {$index} had no values."
                );
            }

            $vectors[] = array_map(static fn (mixed $value): float => (float) $value, array_values($values));
        }

        return $vectors;
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $body = trim($response->body());

        return $body === '' ? 'no detail given' : $body;
    }

    private function assertConfigured(string $model): void
    {
        if ($this->apiKey === '') {
            throw GeminiConfigurationException::missing('GEMINI_API_KEY');
        }

        if ($model === '') {
            throw GeminiConfigurationException::missing('GEMINI_EMBED_MODEL');
        }
    }
}
