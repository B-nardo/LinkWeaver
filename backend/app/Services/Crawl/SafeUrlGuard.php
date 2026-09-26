<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Services\Crawl\Dns\DnsResolver;
use App\Services\Crawl\Exceptions\UnsafeUrlException;

/**
 * Decides whether the crawler is allowed to fetch a URL.
 *
 * Without this, "audit my sitemap" is a request for the server to make HTTP
 * calls anywhere it can reach: cloud metadata endpoints (169.254.169.254),
 * admin panels on localhost, databases bound to a private subnet. The URLs come
 * from a user-supplied sitemap and from hrefs in third-party HTML, so none of
 * them are trustworthy.
 *
 * The guard resolves the host and inspects every address it answers with, not
 * just the first: a name returning one public and one loopback address is still
 * a way in. Redirects must be re-checked at every hop, since the safety of the
 * first URL says nothing about where it points.
 */
final class SafeUrlGuard
{
    /**
     * IPv4 ranges that must never be reachable: loopback, the three private
     * blocks, link-local (which carries the cloud metadata service), carrier
     * NAT, the documentation and benchmarking blocks, multicast and reserved
     * space.
     */
    private const array BLOCKED_IPV4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const array BLOCKED_IPV6 = [
        '::/128',
        '::1/128',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    private const array ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(private readonly DnsResolver $dns) {}

    public function isSafe(string $url): bool
    {
        try {
            $this->assertSafe($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): void
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'])) {
            throw UnsafeUrlException::malformed($url);
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw UnsafeUrlException::scheme($scheme);
        }

        if (! isset($parts['host']) || $parts['host'] === '') {
            throw UnsafeUrlException::malformed($url);
        }

        // user:pass@host is a classic way to disguise the real destination and
        // to leak credentials into logs. There is no legitimate use here.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeUrlException::credentials();
        }

        $host = trim(strtolower($parts['host']), '[]');

        foreach ($this->addressesFor($host) as $address) {
            if ($this->isBlockedAddress($address)) {
                throw UnsafeUrlException::blockedAddress($host, $address);
            }
        }
    }

    /**
     * Addresses to judge: either the host is already a literal, in which case no
     * lookup happens, or it is a name that must resolve to at least one address.
     *
     * @return list<string>
     *
     * @throws UnsafeUrlException
     */
    private function addressesFor(string $host): array
    {
        $literal = $this->asIpLiteral($host);

        if ($literal !== null) {
            return [$literal];
        }

        $addresses = $this->dns->resolve($host);

        if ($addresses === []) {
            throw UnsafeUrlException::unresolvable($host);
        }

        return $addresses;
    }

    /**
     * Interprets a host as an IP address if it is one in any notation, so that
     * obfuscated literals cannot slip past as if they were hostnames.
     *
     * `2130706433`, `0177.0.0.1`, `0x7f000001` and `127.1` are all inet_aton
     * spellings of 127.0.0.1 that a naive dotted-quad check would miss.
     */
    private function asIpLiteral(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        return $this->parseLegacyIpv4($host);
    }

    private function parseLegacyIpv4(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->parseNumericPart($part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        // In inet_aton notation the final part absorbs every remaining byte, so
        // 127.1 is 127.0.0.1 and 2130706433 is the whole address.
        $count = count($values);
        $last = array_pop($values);

        if ($last === null || $last >= 2 ** (8 * (4 - $count + 1))) {
            return null;
        }

        $address = $last;

        foreach ($values as $index => $value) {
            if ($value > 255) {
                return null;
            }

            $address += $value << (8 * (3 - $index));
        }

        return $address > 0xFFFFFFFF ? null : long2ip($address);
    }

    private function parseNumericPart(string $part): ?int
    {
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $part) === 1) {
            return (int) hexdec(substr($part, 2));
        }

        if (preg_match('/^0[0-7]+$/', $part) === 1) {
            return (int) octdec(substr($part, 1));
        }

        if (preg_match('/^(0|[1-9][0-9]*)$/', $part) === 1) {
            return (int) $part;
        }

        return null;
    }

    private function isBlockedAddress(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            // Something that is neither a name nor a parseable address: refuse
            // rather than guess.
            return true;
        }

        if (strlen($binary) === 4) {
            return $this->matchesAny($binary, self::BLOCKED_IPV4);
        }

        // ::ffff:0:0/96 wraps an IPv4 address inside an IPv6 one. Judge it by
        // the address it actually carries, or ::ffff:127.0.0.1 walks straight
        // through the IPv6 rules.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return $this->matchesAny(substr($binary, 12), self::BLOCKED_IPV4);
        }

        return $this->matchesAny($binary, self::BLOCKED_IPV6);
    }

    /**
     * @param  list<string>  $cidrs
     */
    private function matchesAny(string $binary, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->matches($binary, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $binary, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $subnetBinary = inet_pton($subnet);

        if ($subnetBinary === false || strlen($subnetBinary) !== strlen($binary)) {
            return false;
        }

        $prefixBits = (int) $bits;
        $wholeBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;

        if ($wholeBytes > 0 && strncmp($binary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($binary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
