<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

/**
 * Separates a page's actual content from its furniture.
 *
 * This decision is load-bearing twice over. The text feeds the embeddings, so
 * including navigation and footer copy would make every page on a site look
 * topically identical. The links decide the graph, so counting a site-wide
 * footer link as a real internal link would make every page appear well linked
 * and hide every orphan — the exact thing the product exists to find.
 */
final class ContentExtractor
{
    /**
     * Tried in order. The first candidate with enough words wins; a theme that
     * matches none of them falls through to readability.
     */
    public const array CONTENT_SELECTORS = [
        'article',
        'main',
        '.entry-content',
        '.post-content',
        '.wp-block-post-content',
        '#content',
    ];

    /**
     * Removed before the text is taken, and used to disqualify links from
     * counting as in-content.
     */
    public const array STRIP_SELECTORS = [
        'nav', 'header', 'footer', 'aside', 'form', 'script', 'style', 'noscript', 'template',
        '.comments', '#comments', '.comments-area', '.comment-respond',
        '.related-posts', '.related', '.yarpp-related', '.ast-single-related-posts',
        '.share', '.sharedaddy', '.social-share',
        '.sidebar', '#sidebar', '.widget', '.widget-area',
        '.breadcrumb', '.breadcrumbs',
        '.post-navigation', '.wp-block-post-navigation-link',
        '.screen-reader-text', '.skip-link',
    ];

    /**
     * Below this, a candidate is assumed to be a wrapper that happened to match
     * rather than the real body, and the next strategy is tried.
     */
    public const int MIN_CONTENT_WORDS = 50;

    /**
     * @param  list<string>  $contentSelectors
     * @param  list<string>  $stripSelectors
     */
    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly array $contentSelectors = self::CONTENT_SELECTORS,
        private readonly array $stripSelectors = self::STRIP_SELECTORS,
        private readonly int $minContentWords = self::MIN_CONTENT_WORDS,
    ) {}

    public function extract(string $html, string $pageUrl): ExtractedPage
    {
        if (trim($html) === '') {
            return ExtractedPage::empty();
        }

        $document = $this->loadDocument($html);

        if ($document === null) {
            return ExtractedPage::empty();
        }

        $xpath = new DOMXPath($document);
        $stripped = $this->nodesMatching($xpath, $this->stripSelectors);

        [$contentNode, $usedFallback] = $this->findContent($document, $xpath, $stripped, $html);

        $text = $contentNode === null
            ? ''
            : $this->textOf($contentNode, $stripped);

        return new ExtractedPage(
            title: $this->firstText($xpath, '//title'),
            h1: $this->firstText($xpath, '//h1'),
            metaDescription: $this->metaDescription($xpath),
            text: $text,
            wordCount: $this->countWords($text),
            contentHash: hash('sha256', $text),
            links: $this->extractLinks($xpath, $pageUrl, $contentNode, $stripped),
            usedFallback: $usedFallback,
        );
    }

    /**
     * Picks the node holding the article body.
     *
     * @param  list<DOMNode>  $stripped
     * @return array{0: ?DOMNode, 1: bool}
     */
    private function findContent(DOMDocument $document, DOMXPath $xpath, array $stripped, string $html): array
    {
        foreach ($this->contentSelectors as $selector) {
            foreach ($this->nodesMatching($xpath, [$selector]) as $candidate) {
                if ($this->countWords($this->textOf($candidate, $stripped)) >= $this->minContentWords) {
                    return [$candidate, false];
                }
            }
        }

        $fallback = $this->readabilityNode($html);

        if ($fallback !== null) {
            return [$fallback, true];
        }

        // Nothing matched and readability declined: fall back to the body so a
        // short page still yields its text rather than nothing at all.
        return [$document->getElementsByTagName('body')->item(0), false];
    }

    /**
     * Runs readability over the raw HTML and re-imports its cleaned output.
     *
     * Readability is a genuinely different strategy — it scores paragraph
     * density instead of trusting markup — which is why it is worth keeping for
     * themes that use no semantic containers at all.
     */
    private function readabilityNode(string $html): ?DOMNode
    {
        try {
            $readability = new Readability(new Configuration);

            if (! $readability->parse($html)) {
                return null;
            }

            $content = $readability->getContent();

            if ($content === null || trim($content) === '') {
                return null;
            }

            $document = $this->loadDocument('<!DOCTYPE html><html><body>'.$content.'</body></html>');

            return $document?->getElementsByTagName('body')->item(0);
        } catch (Throwable) {
            // Readability is a heuristic over hostile input; a failure here is a
            // reason to give up on the fallback, not to fail the crawl.
            return null;
        }
    }

    /**
     * @param  list<DOMNode>  $stripped
     * @return list<ExtractedLink>
     */
    private function extractLinks(DOMXPath $xpath, string $pageUrl, ?DOMNode $contentNode, array $stripped): array
    {
        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim($anchor->getAttribute('href'));
            $normalized = $this->normalizer->normalize($href, $pageUrl);

            // Only same-site links can become internal link targets; mailto,
            // tel, javascript and external hosts are all discarded here.
            if ($normalized === null || ! $this->normalizer->isInternal($normalized)) {
                continue;
            }

            $inContent = $contentNode !== null
                && $this->isDescendantOf($anchor, $contentNode)
                && ! $this->isInsideAny($anchor, $stripped);

            // Keyed by target so a page linking to the same place twice counts
            // once. An in-content occurrence always wins over a furniture one.
            if (isset($links[$normalized]) && ! $inContent) {
                continue;
            }

            $links[$normalized] = new ExtractedLink(
                url: $normalized,
                rawHref: $href,
                anchorText: $this->collapseWhitespace($anchor->textContent),
                inContent: $inContent,
            );
        }

        return array_values($links);
    }

    /**
     * @param  list<string>  $selectors
     * @return list<DOMNode>
     */
    private function nodesMatching(DOMXPath $xpath, array $selectors): array
    {
        $converter = new CssSelectorConverter;
        $nodes = [];

        foreach ($selectors as $selector) {
            try {
                $expression = $converter->toXPath($selector);
            } catch (Throwable) {
                continue;
            }

            foreach ($xpath->query($expression) ?: [] as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Text of a node with every stripped region removed.
     *
     * Walks the tree rather than mutating the document, because the same
     * document is reused to classify links, and deleting nodes would destroy the
     * ancestry checks that decide what counts as in-content.
     *
     * @param  list<DOMNode>  $stripped
     */
    private function textOf(DOMNode $node, array $stripped): string
    {
        if ($this->isInsideAny($node, $stripped) || $this->isAny($node, $stripped)) {
            return '';
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }

        $parts = [];

        foreach ($node->childNodes as $child) {
            $text = $this->textOf($child, $stripped);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $this->collapseWhitespace(implode(' ', $parts));
    }

    /**
     * @param  list<DOMNode>  $candidates
     */
    private function isAny(DOMNode $node, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($node->isSameNode($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<DOMNode>  $candidates
     */
    private function isInsideAny(DOMNode $node, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($this->isDescendantOf($node, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isDescendantOf(DOMNode $node, DOMNode $ancestor): bool
    {
        for ($current = $node->parentNode; $current !== null; $current = $current->parentNode) {
            if ($current->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    private function firstText(DOMXPath $xpath, string $expression): ?string
    {
        $node = $xpath->query($expression)?->item(0);

        if ($node === null) {
            return null;
        }

        $text = $this->collapseWhitespace($node->textContent);

        return $text === '' ? null : $text;
    }

    private function metaDescription(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content')?->item(0);

        if ($node === null) {
            return null;
        }

        $text = $this->collapseWhitespace($node->nodeValue ?? '');

        return $text === '' ? null : $text;
    }

    private function countWords(string $text): int
    {
        $trimmed = trim($text);

        return $trimmed === '' ? 0 : count(preg_split('/\s+/', $trimmed) ?: []);
    }

    private function collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function loadDocument(string $html): ?DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // The meta hint forces UTF-8: loadHTML otherwise assumes ISO-8859-1 and
        // mangles every non-ASCII character in the content.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
