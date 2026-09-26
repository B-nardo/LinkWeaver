<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Services\Crawl\Exceptions\UnsafeUrlException;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * The only way the application is allowed to make an outbound request.
 *
 * Centralising this is what makes the SSRF guarantee hold: a single `Http::get`
 * anywhere else in the codebase would bypass SafeUrlGuard entirely. Every hop of
 * a redirect chain is re-validated, because a URL that was safe when we asked
 * says nothing about where the server chose to send us.
 */
final class SafeHttpFetcher
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly SafeUrlGuard $guard,
        private readonly string $userAgent,
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly int $maxBytes,
        private readonly int $maxRedirects,
    ) {}

    /**
     * @throws UnsafeUrlException
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function fetch(string $url): FetchedResponse
    {
        $this->guard->assertSafe($url);

        $response = $this->http
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate',
            ])
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withOptions([
                'allow_redirects' => [
                    'max' => $this->maxRedirects,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                    // Re-validate every hop. Throwing here aborts the transfer
                    // before the request to the unsafe destination is made.
                    'on_redirect' => function (
                        RequestInterface $request,
                        ResponseInterface $response,
                        UriInterface $uri
                    ): void {
                        $this->guard->assertSafe((string) $uri);
                    },
                ],
            ])
            ->get($url);

        $body = $response->body();
        $truncated = strlen($body) > $this->maxBytes;

        return new FetchedResponse(
            status: $response->status(),
            // A hostile or misconfigured server can stream far more than it
            // announced, so the cap is enforced on what actually arrived.
            body: $truncated ? substr($body, 0, $this->maxBytes) : $body,
            finalUrl: $this->finalUrlOf($response, $url),
            contentType: $response->header('Content-Type') ?: null,
            truncated: $truncated,
        );
    }

    /**
     * Guzzle records the redirect chain in a header when `track_redirects` is
     * on; the last entry is where the request actually landed.
     */
    private function finalUrlOf(\Illuminate\Http\Client\Response $response, string $requestedUrl): string
    {
        $chain = $response->getHeader('X-Guzzle-Redirect-History');

        if ($chain === []) {
            return $requestedUrl;
        }

        $last = (string) end($chain);

        return $last === '' ? $requestedUrl : (string) new Uri($last);
    }
}
