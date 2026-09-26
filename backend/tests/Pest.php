<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|------------------------------------------------------------------------------
| Test case bindings
|------------------------------------------------------------------------------
|
| Feature tests boot the application and run inside a transaction that rolls
| back after each test. Unit tests deliberately get no application container:
| the correctness-critical classes (UrlNormalizer, SafeUrlGuard, SitemapParser,
| ContentExtractor) are plain objects, and keeping them framework-free keeps the
| unit suite fast and forces their dependencies to stay explicit.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
