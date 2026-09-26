<?php

declare(strict_types=1);

namespace App\Services\Crawl\Dns;

/**
 * Resolves through the host system's resolver.
 *
 * Both record types are queried deliberately: a name that returns a harmless
 * A record and a loopback AAAA record is still a way into the machine, so the
 * guard needs to see every address before it allows the request.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        $ipv4 = gethostbynamel($host);

        if ($ipv4 !== false) {
            $addresses = $ipv4;
        }

        // Suppressed rather than checked: dns_get_record emits a warning for
        // names that simply have no AAAA record, which is not an error here.
        $ipv6 = @dns_get_record($host, DNS_AAAA);

        if ($ipv6 !== false) {
            foreach ($ipv6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
