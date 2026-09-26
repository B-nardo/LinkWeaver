<?php

declare(strict_types=1);

namespace App\Services\Crawl\Dns;

/**
 * Name resolution, behind an interface purely so SafeUrlGuard can be tested
 * without touching a real resolver. A guard whose tests need the network is a
 * guard nobody runs.
 */
interface DnsResolver
{
    /**
     * Every address the host resolves to, IPv4 and IPv6. An empty list means
     * the name does not resolve, which callers must treat as unsafe.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
