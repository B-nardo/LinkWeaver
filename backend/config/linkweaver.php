<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Crawl limits
    |---------------------------------------------------------------------------
    |
    | Hard ceiling on how many pages a single project may process. This protects
    | the free-tier infrastructure the app is designed to run on: the similarity
    | stage is O(n^2) in memory, so a few hundred pages is the intended scale.
    |
    */

    'max_pages' => (int) env('LINKWEAVER_MAX_PAGES', 200),

    /*
    |---------------------------------------------------------------------------
    | Sitemap parsing
    |---------------------------------------------------------------------------
    |
    | 'max_depth' bounds recursion through nested <sitemapindex> documents so a
    | malicious or misconfigured sitemap cannot fan out indefinitely.
    |
    | Taxonomy sitemaps (category/tag/author archives) are skipped by default:
    | they are aggregations, not content, and they pollute both the link graph
    | and the similarity analysis. Overridable per project.
    |
    */

    'sitemap' => [
        'max_depth' => (int) env('LINKWEAVER_SITEMAP_MAX_DEPTH', 3),
        'skip_taxonomies' => (bool) env('LINKWEAVER_SKIP_TAXONOMIES', true),

        'taxonomy_patterns' => [
            'category', 'categories', 'tag', 'tags', 'author', 'authors',
            'post_tag', 'product_cat', 'product_tag',
        ],

        'skip_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tiff',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv',
            'zip', 'gz', 'tar', 'rar', '7z',
            'mp3', 'mp4', 'wav', 'avi', 'mov', 'webm', 'ogg',
            'css', 'js', 'json', 'rss', 'atom', 'exe', 'dmg',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Outbound HTTP
    |---------------------------------------------------------------------------
    |
    | Applied by SafeHttpFetcher to every outbound request. 'max_bytes' guards
    | against decompression/response-size abuse; 'max_redirects' is deliberately
    | low because each hop must be re-validated by SafeUrlGuard.
    |
    */

    'http' => [
        'user_agent' => env(
            'LINKWEAVER_USER_AGENT',
            'LinkweaverBot/1.0 (+https://github.com/linkweaver; internal link auditor)'
        ),
        'connect_timeout' => (int) env('LINKWEAVER_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('LINKWEAVER_TIMEOUT', 15),
        'max_bytes' => (int) env('LINKWEAVER_MAX_BYTES', 2 * 1024 * 1024),
        'max_redirects' => (int) env('LINKWEAVER_MAX_REDIRECTS', 3),

        'accept_content_types' => ['text/html', 'application/xhtml+xml'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Politeness
    |---------------------------------------------------------------------------
    |
    | Per-domain request throttle enforced by job middleware, plus robots.txt
    | handling. A Crawl-delay directive is honoured when it is stricter than our
    | own throttle, but capped so one hostile robots.txt cannot stall a project.
    |
    */

    'throttle' => [
        'requests_per_second' => (int) env('LINKWEAVER_REQUESTS_PER_SECOND', 2),
    ],

    'robots' => [
        'respect' => (bool) env('LINKWEAVER_RESPECT_ROBOTS', true),
        'cache_ttl' => (int) env('LINKWEAVER_ROBOTS_CACHE_TTL', 3600),
        'max_crawl_delay' => (int) env('LINKWEAVER_MAX_CRAWL_DELAY', 5),
    ],

    /*
    |---------------------------------------------------------------------------
    | Content extraction
    |---------------------------------------------------------------------------
    |
    | Selectors are tried in order; the readability fallback runs only when the
    | best candidate yields fewer than 'min_content_words' words.
    |
    */

    'extraction' => [
        'content_selectors' => [
            'article',
            'main',
            '.entry-content',
            '.post-content',
            '.wp-block-post-content',
        ],

        'strip_selectors' => [
            'nav', 'header', 'footer', 'aside', 'form', 'script', 'style', 'noscript',
            '.comments', '#comments', '.comment-respond',
            '.related-posts', '.related', '.yarpp-related',
            '.share', '.sharedaddy', '.social-share',
            '.sidebar', '#sidebar', '.widget', '.widget-area',
            '.breadcrumb', '.breadcrumbs',
            '.wp-block-post-navigation-link', '.post-navigation',
            '.screen-reader-text', '.skip-link',
        ],

        'min_content_words' => (int) env('LINKWEAVER_MIN_CONTENT_WORDS', 50),
    ],

    /*
    |---------------------------------------------------------------------------
    | Analysis (phases 2-3)
    |---------------------------------------------------------------------------
    |
    | A page with 0 inbound in-content links is an orphan; 1-2 is "weak".
    |
    | priority_score = (similarity * similarity_weight)
    |                + (inbound_scarcity * scarcity_weight)
    |
    | where inbound_scarcity decays as the target accumulates inbound links, so
    | orphans and weak pages surface above already well-linked pages.
    |
    */

    'analysis' => [
        'weak_inbound_threshold' => (int) env('LINKWEAVER_WEAK_THRESHOLD', 2),
        'similarity_threshold' => (float) env('LINKWEAVER_SIMILARITY_THRESHOLD', 0.75),
        'candidates_per_page' => (int) env('LINKWEAVER_CANDIDATES_PER_PAGE', 5),
        'similarity_weight' => (float) env('LINKWEAVER_SIMILARITY_WEIGHT', 0.6),
        'scarcity_weight' => (float) env('LINKWEAVER_SCARCITY_WEIGHT', 0.4),
    ],

    /*
    |---------------------------------------------------------------------------
    | Anchor validation (phase 4)
    |---------------------------------------------------------------------------
    |
    | Model output is untrusted. An anchor is only stored if it parses as JSON,
    | falls within the word bounds, and appears verbatim in the source content
    | outside of any existing link or heading.
    |
    */

    'anchors' => [
        'min_words' => (int) env('LINKWEAVER_ANCHOR_MIN_WORDS', 2),
        'max_words' => (int) env('LINKWEAVER_ANCHOR_MAX_WORDS', 6),
    ],

    /*
    |---------------------------------------------------------------------------
    | Gemini (phase 3+)
    |---------------------------------------------------------------------------
    |
    | Model names are intentionally NOT defaulted: they must be supplied by env
    | and verified against Google's current API docs before use.
    |
    */

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'embed_model' => env('GEMINI_EMBED_MODEL'),
        'text_model' => env('GEMINI_TEXT_MODEL'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'embed_batch_size' => (int) env('GEMINI_EMBED_BATCH_SIZE', 50),
        'requests_per_minute' => (int) env('GEMINI_REQUESTS_PER_MINUTE', 12),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 3),
    ],

    /*
    |---------------------------------------------------------------------------
    | Rate limits
    |---------------------------------------------------------------------------
    */

    'rate_limits' => [
        'project_creation_per_user_hourly' => (int) env('LINKWEAVER_PROJECTS_PER_USER_HOURLY', 5),
        'project_creation_per_ip_hourly' => (int) env('LINKWEAVER_PROJECTS_PER_IP_HOURLY', 15),
    ],

];
