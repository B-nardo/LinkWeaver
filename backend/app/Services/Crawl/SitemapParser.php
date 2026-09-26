<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Services\Crawl\Exceptions\UnreadableSitemapException;
use LibXMLError;
use SimpleXMLElement;

/**
 * Reads a single sitemap document.
 *
 * Fetching and recursion deliberately live elsewhere: keeping this class pure
 * means its many edge cases — namespaced and bare documents, gzip, taxonomy
 * archives, non-HTML resources, hostile XML — are cheap to cover in unit tests
 * without a network or a container.
 */
final class SitemapParser
{
    /**
     * Sitemaps routinely list assets and feeds alongside pages. Fetching them
     * wastes the page budget and produces rows with no extractable content.
     */
    public const array SKIP_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tiff',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv',
        'zip', 'gz', 'tar', 'rar', '7z',
        'mp3', 'mp4', 'wav', 'avi', 'mov', 'webm', 'ogg',
        'css', 'js', 'json', 'xml', 'rss', 'atom', 'exe', 'dmg',
    ];

    /**
     * Archive pages that aggregate other content. They are not destinations
     * worth linking to, and their outbound links would make every post look
     * well connected.
     */
    public const array TAXONOMY_TOKENS = [
        'category', 'categories', 'tag', 'tags', 'author', 'authors',
        'cat', 'product_cat', 'product_tag',
    ];

    /**
     * @param  list<string>  $skipExtensions
     * @param  list<string>  $taxonomyTokens
     */
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly array $skipExtensions = self::SKIP_EXTENSIONS,
        private readonly array $taxonomyTokens = self::TAXONOMY_TOKENS,
    ) {}

    /**
     * @throws UnreadableSitemapException
     */
    public function parse(string $body, bool $skipTaxonomies = true): ParsedSitemap
    {
        $xml = $this->loadXml($body);
        $namespace = $xml->getNamespaces()[''] ?? null;

        return match ($xml->getName()) {
            'sitemapindex' => new ParsedSitemap(
                isIndex: true,
                sitemapUrls: $this->collectSitemaps($xml, $namespace, $skipTaxonomies),
            ),
            'urlset' => new ParsedSitemap(
                isIndex: false,
                pageUrls: $this->collectPages($xml, $namespace),
            ),
            default => throw UnreadableSitemapException::notASitemap($xml->getName()),
        };
    }

    /**
     * @return list<string>
     */
    private function collectSitemaps(SimpleXMLElement $xml, ?string $namespace, bool $skipTaxonomies): array
    {
        $urls = [];

        foreach ($this->childrenNamed($xml, $namespace, 'sitemap') as $node) {
            $normalized = $this->normalizer->normalize($this->locationOf($node, $namespace));

            if ($normalized === null) {
                continue;
            }

            if ($skipTaxonomies && $this->isTaxonomySitemap($normalized)) {
                continue;
            }

            $urls[$normalized] = true;
        }

        return array_keys($urls);
    }

    /**
     * @return list<string>
     */
    private function collectPages(SimpleXMLElement $xml, ?string $namespace): array
    {
        $urls = [];

        foreach ($this->childrenNamed($xml, $namespace, 'url') as $node) {
            $location = $this->locationOf($node, $namespace);
            $normalized = $this->normalizer->normalize($location);

            // Pages on another domain are out of scope: the project audits one
            // site, and a page we cannot crawl cannot be a link target.
            if ($normalized === null || ! $this->normalizer->isInternal($normalized)) {
                continue;
            }

            if ($this->hasSkippedExtension($normalized)) {
                continue;
            }

            // Keyed to deduplicate: sitemaps frequently list both the slashed
            // and unslashed spelling of the same page.
            $urls[$normalized] = true;
        }

        return array_keys($urls);
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function childrenNamed(SimpleXMLElement $xml, ?string $namespace, string $name): array
    {
        $children = $namespace !== null ? $xml->children($namespace) : $xml->children();
        $matching = [];

        foreach ($children as $child) {
            if ($child->getName() === $name) {
                $matching[] = $child;
            }
        }

        return $matching;
    }

    private function locationOf(SimpleXMLElement $node, ?string $namespace): string
    {
        $children = $namespace !== null ? $node->children($namespace) : $node->children();

        return trim((string) $children->loc);
    }

    private function hasSkippedExtension(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, $this->skipExtensions, true);
    }

    /**
     * Matches whole tokens of the filename rather than a substring, so
     * `category-sitemap.xml` is skipped while `vintage-sitemap.xml` is not.
     */
    private function isTaxonomySitemap(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $tokens = preg_split('/[^a-z0-9]+/i', strtolower(basename($path))) ?: [];

        foreach ($tokens as $token) {
            if (in_array($token, $this->taxonomyTokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws UnreadableSitemapException
     */
    private function loadXml(string $body): SimpleXMLElement
    {
        // Decompress before trimming: gzip's trailing CRC and length bytes can
        // legitimately be NUL or vertical tab, which trim() would eat, leaving a
        // corrupt stream that no longer decodes.
        $body = trim($this->decompress($body));

        if ($body === '') {
            throw UnreadableSitemapException::empty();
        }

        // Strip any DOCTYPE, including an internal subset, before parsing.
        // Sitemaps have no legitimate use for one, and it is the vehicle for
        // entity-expansion attacks against a server fetching hostile input.
        $body = (string) preg_replace('/<!DOCTYPE\s+[^\[>]*(\[[^\]]*\])?\s*>/is', '', $body, 1);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw UnreadableSitemapException::malformed(
                trim($errors[0]->message ?? 'unknown parse error')
            );
        }

        // A document can parse while still containing errors, notably undefined
        // entity references left behind once the DOCTYPE is removed. Those are
        // rejected rather than silently yielding truncated URLs.
        foreach ($errors as $error) {
            if ($this->isFatal($error)) {
                throw UnreadableSitemapException::malformed(trim($error->message));
            }
        }

        return $xml;
    }

    private function isFatal(LibXMLError $error): bool
    {
        return $error->level === LIBXML_ERR_FATAL || $error->level === LIBXML_ERR_ERROR;
    }

    private function decompress(string $body): string
    {
        // Sitemaps are commonly served as .xml.gz. Detected by magic number
        // rather than by file extension, which the transport may have stripped.
        if (! str_starts_with($body, "\x1f\x8b")) {
            return $body;
        }

        $decoded = @gzdecode($body);

        return $decoded === false ? $body : $decoded;
    }
}
