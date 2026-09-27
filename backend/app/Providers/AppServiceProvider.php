<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Analysis\CandidateGenerator;
use App\Services\Analysis\LinkGraphBuilder;
use App\Services\Analysis\PageLinkQuery;
use App\Services\Anchors\AnchorValidator;
use App\Services\Crawl\Dns\DnsResolver;
use App\Services\Crawl\Dns\SystemDnsResolver;
use App\Services\Gemini\GeminiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound rather than auto-resolved so tests can swap in a fake resolver
        // and exercise SafeUrlGuard without touching real DNS.
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        // Both read their thresholds from config rather than taking them as
        // autowired primitives, so a controller can type-hint them directly.
        $this->app->bind(PageLinkQuery::class, fn (): PageLinkQuery => PageLinkQuery::fromConfig());
        $this->app->bind(LinkGraphBuilder::class, fn (): LinkGraphBuilder => LinkGraphBuilder::fromConfig());
        $this->app->bind(CandidateGenerator::class, fn (): CandidateGenerator => CandidateGenerator::fromConfig());
        $this->app->bind(AnchorValidator::class, fn (): AnchorValidator => AnchorValidator::fromConfig());

        $this->app->bind(
            GeminiClient::class,
            fn ($app): GeminiClient => GeminiClient::fromConfig($app->make(HttpFactory::class)),
        );
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Creating a project starts a crawl of a third-party site, so it is limited
     * per user and per IP: the first stops one account queueing unbounded work,
     * the second stops a pool of throwaway accounts being used to point the
     * crawler at a victim.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('projects', fn (Request $request): array => [
            Limit::perHour((int) config('linkweaver.rate_limits.project_creation_per_user_hourly'))
                ->by('user:'.($request->user()?->id ?? 'guest')),
            Limit::perHour((int) config('linkweaver.rate_limits.project_creation_per_ip_hourly'))
                ->by('ip:'.$request->ip()),
        ]);

        // Throttles the embedding job. Gemini's free-tier limits are not
        // published, so the default is deliberately conservative; raise
        // GEMINI_REQUESTS_PER_MINUTE once you have checked yours in AI Studio.
        RateLimiter::for('gemini', fn (): Limit => Limit::perMinute(
            (int) config('linkweaver.gemini.requests_per_minute')
        )->by('gemini'));

        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
