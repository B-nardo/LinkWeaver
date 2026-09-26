<?php

declare(strict_types=1);

use App\Services\Crawl\Dns\DnsResolver;
use App\Services\Crawl\Exceptions\UnsafeUrlException;
use App\Services\Crawl\SafeUrlGuard;

/*
|------------------------------------------------------------------------------
| SafeUrlGuard
|------------------------------------------------------------------------------
|
| The crawler fetches URLs that a user supplied and that arbitrary third-party
| HTML pointed at. Without this guard, "audit my sitemap" is a request to make
| the server issue HTTP calls to anywhere the server can reach — cloud metadata
| endpoints, internal admin panels, databases bound to localhost.
|
| DNS is injected so these tests are hermetic: no name is ever really resolved.
|
*/

/**
 * Resolver that answers from a fixed map, and fails loudly on anything the test
 * did not explicitly set up.
 */
function fakeDns(array $map = []): DnsResolver
{
    return new class($map) implements DnsResolver
    {
        public function __construct(private array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    };
}

function guard(array $dnsMap = ['example.com' => ['93.184.216.34']]): SafeUrlGuard
{
    return new SafeUrlGuard(fakeDns($dnsMap));
}

describe('permitted destinations', function (): void {
    it('allows a public host', function (): void {
        expect(guard()->isSafe('https://example.com/page/'))->toBeTrue();
    });

    it('allows a public IP literal without consulting DNS', function (): void {
        expect(guard([])->isSafe('https://93.184.216.34/page/'))->toBeTrue();
    });

    it('allows a public IPv6 literal', function (): void {
        expect(guard([])->isSafe('https://[2606:2800:220:1:248:1893:25c8:1946]/'))->toBeTrue();
    });
});

describe('scheme restrictions', function (): void {
    it('rejects any scheme other than http and https', function (string $url): void {
        expect(guard()->isSafe($url))->toBeFalse();
    })->with([
        'file:///etc/passwd',
        'ftp://example.com/x',
        'gopher://example.com/x',
        'dict://example.com/x',
        'jar:http://example.com/x!/',
        'mailto:hello@example.com',
    ]);

    it('allows plain http', function (): void {
        expect(guard()->isSafe('http://example.com/'))->toBeTrue();
    });
});

describe('malformed input', function (): void {
    it('rejects input that does not carry a host', function (string $url): void {
        expect(guard()->isSafe($url))->toBeFalse();
    })->with(['', '   ', 'https://', 'not a url', '/relative/path/']);

    it('rejects credentials embedded in the URL', function (): void {
        expect(guard(['internal.test' => ['93.184.216.34']])
            ->isSafe('https://admin:hunter2@internal.test/'))->toBeFalse();
    });

    it('rejects a host that does not resolve', function (): void {
        expect(guard([])->isSafe('https://nowhere.invalid/'))->toBeFalse();
    });
});

describe('IPv4 ranges that must never be reachable', function (): void {
    it('rejects a hostname resolving into a blocked range', function (string $ip): void {
        expect(guard(['internal.test' => [$ip]])->isSafe('https://internal.test/'))->toBeFalse();
    })->with([
        'unspecified' => '0.0.0.0',
        'this network' => '0.1.2.3',
        'loopback' => '127.0.0.1',
        'loopback range' => '127.99.1.5',
        'private 10/8' => '10.0.0.1',
        'private 172.16/12' => '172.16.5.4',
        'private 172.31' => '172.31.255.254',
        'private 192.168/16' => '192.168.1.1',
        'link-local' => '169.254.169.254',
        'carrier NAT' => '100.64.0.1',
        'IETF protocol' => '192.0.0.1',
        'TEST-NET-1' => '192.0.2.1',
        'benchmarking' => '198.18.0.1',
        'TEST-NET-2' => '198.51.100.1',
        'TEST-NET-3' => '203.0.113.1',
        'multicast' => '224.0.0.1',
        'reserved' => '240.0.0.1',
        'broadcast' => '255.255.255.255',
    ]);

    it('rejects a blocked address written directly as a literal', function (): void {
        expect(guard([])->isSafe('http://169.254.169.254/latest/meta-data/'))->toBeFalse();
    });

    it('still allows an address just outside a blocked range', function (string $ip): void {
        expect(guard(['edge.test' => [$ip]])->isSafe('https://edge.test/'))->toBeTrue();
    })->with([
        'below 10/8' => '9.255.255.255',
        'above 10/8' => '11.0.0.1',
        'below 172.16/12' => '172.15.255.255',
        'above 172.16/12' => '172.32.0.1',
        'below 192.168/16' => '192.167.255.255',
        'public' => '8.8.8.8',
    ]);
});

describe('IPv4 written in obfuscated forms', function (): void {
    it('decodes alternative literal notations before judging them', function (string $host): void {
        expect(guard([])->isSafe("http://{$host}/"))->toBeFalse();
    })->with([
        'decimal loopback' => '2130706433',
        'octal loopback' => '0177.0.0.1',
        'hex loopback' => '0x7f.0x0.0x0.0x1',
        'single hex' => '0x7f000001',
        'short form' => '127.1',
    ]);
});

describe('IPv6 ranges that must never be reachable', function (): void {
    it('rejects blocked IPv6 destinations', function (string $ip): void {
        expect(guard(['internal.test' => [$ip]])->isSafe('https://internal.test/'))->toBeFalse();
    })->with([
        'loopback' => '::1',
        'unspecified' => '::',
        'unique local' => 'fc00::1',
        'unique local fd' => 'fd12:3456:789a::1',
        'link-local' => 'fe80::1',
        'multicast' => 'ff02::1',
        'documentation' => '2001:db8::1',
        'discard' => '100::1',
    ]);

    it('rejects an IPv4-mapped IPv6 address wrapping a blocked range', function (string $ip): void {
        expect(guard(['internal.test' => [$ip]])->isSafe('https://internal.test/'))->toBeFalse();
    })->with([
        'mapped loopback' => '::ffff:127.0.0.1',
        'mapped metadata' => '::ffff:169.254.169.254',
        'mapped private' => '::ffff:10.0.0.1',
        'mapped hex form' => '::ffff:7f00:1',
    ]);

    it('rejects a blocked IPv6 literal in the URL', function (): void {
        expect(guard([])->isSafe('http://[::1]:8080/admin'))->toBeFalse();
    });
});

describe('multiple DNS answers', function (): void {
    it('rejects when any answer is blocked, not just the first', function (): void {
        expect(guard(['rebind.test' => ['93.184.216.34', '127.0.0.1']])
            ->isSafe('https://rebind.test/'))->toBeFalse();
    });

    it('allows only when every answer is public', function (): void {
        expect(guard(['multi.test' => ['93.184.216.34', '8.8.8.8']])
            ->isSafe('https://multi.test/'))->toBeTrue();
    });
});

describe('assertSafe', function (): void {
    it('passes silently for a safe URL', function (): void {
        guard()->assertSafe('https://example.com/');
    })->throwsNoExceptions();

    it('throws for a blocked URL', function (): void {
        guard(['internal.test' => ['127.0.0.1']])->assertSafe('https://internal.test/');
    })->throws(UnsafeUrlException::class);

    it('names the reason so crawl failures are diagnosable', function (): void {
        expect(fn () => guard([])->assertSafe('file:///etc/passwd'))
            ->toThrow(UnsafeUrlException::class, 'scheme');
    });
});
