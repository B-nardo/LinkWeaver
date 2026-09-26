<?php

declare(strict_types=1);

namespace App\Services\Crawl;

/**
 * Parsed robots.txt rules for one user agent.
 *
 * Crawling a stranger's site on a user's behalf only stays defensible if the
 * crawler asks permission first. Parsing is kept apart from fetching so the
 * precedence rules — the part that is genuinely easy to get wrong — can be unit
 * tested without a network.
 */
final class RobotsTxt
{
    /**
     * @param  list<array{allow: bool, pattern: string}>  $rules
     */
    private function __construct(
        private readonly array $rules,
        private readonly ?float $crawlDelay,
    ) {}

    /**
     * Permits everything. Used when robots.txt is absent or unreadable, which
     * the standard treats as unrestricted access.
     */
    public static function permissive(): self
    {
        return new self([], null);
    }

    public static function parse(string $body, string $userAgent): self
    {
        $userAgent = strtolower($userAgent);

        /** @var array<string, list<array{allow: bool, pattern: string}>> $groups */
        $groups = [];
        /** @var array<string, float> $delays */
        $delays = [];

        /** @var list<string> $currentAgents */
        $currentAgents = [];
        $previousLineWasAgent = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(self::stripComment($line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                // Consecutive User-agent lines share one rule block; a line
                // after any rule starts a new block.
                if (! $previousLineWasAgent) {
                    $currentAgents = [];
                }

                $currentAgents[] = strtolower($value);
                $previousLineWasAgent = true;

                continue;
            }

            $previousLineWasAgent = false;

            if ($currentAgents === []) {
                continue;
            }

            foreach ($currentAgents as $agent) {
                if ($field === 'crawl-delay' && is_numeric($value)) {
                    $delays[$agent] = (float) $value;

                    continue;
                }

                if ($field === 'allow' || $field === 'disallow') {
                    // "Disallow:" with no value is an explicit grant of access,
                    // not a rule blocking the empty path.
                    if ($field === 'disallow' && $value === '') {
                        continue;
                    }

                    $groups[$agent][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            }
        }

        // A group naming us wins outright over the wildcard group, even if the
        // wildcard group is more restrictive.
        $selected = array_key_exists($userAgent, $groups) ? $userAgent : '*';

        return new self(
            rules: $groups[$selected] ?? [],
            crawlDelay: $delays[$selected] ?? $delays['*'] ?? null,
        );
    }

    /**
     * Whether the crawler may fetch this path. Accepts a full URL or a path.
     */
    public function allows(string $pathOrUrl): bool
    {
        $path = $this->pathOf($pathOrUrl);

        $bestLength = -1;
        $allowed = true;

        foreach ($this->rules as $rule) {
            if (! $this->matches($path, $rule['pattern'])) {
                continue;
            }

            $length = strlen($rule['pattern']);

            // Longest match wins; Allow beats Disallow at equal length, which is
            // how both Google and the RFC resolve a tie.
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    public function crawlDelay(): ?float
    {
        return $this->crawlDelay;
    }

    private function matches(string $path, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $anchored = str_ends_with($pattern, '$');

        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        // `*` is the only wildcard; everything else in the pattern is literal.
        $expression = implode('.*', array_map(
            static fn (string $part): string => preg_quote($part, '#'),
            explode('*', $pattern)
        ));

        return preg_match('#^'.$expression.($anchored ? '$' : '').'#', $path) === 1;
    }

    private function pathOf(string $pathOrUrl): string
    {
        if (! str_contains($pathOrUrl, '://')) {
            return $pathOrUrl;
        }

        $path = parse_url($pathOrUrl, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function stripComment(string $line): string
    {
        $position = strpos($line, '#');

        return $position === false ? $line : substr($line, 0, $position);
    }
}
