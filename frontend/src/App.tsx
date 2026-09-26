import { useQuery } from '@tanstack/react-query'
import { Route, Routes } from 'react-router-dom'

import { ApiError, apiFetch } from './lib/api'
import { useTheme } from './lib/theme-context'

/**
 * Phase 0 shell. Real screens arrive with the routes that serve them; what this
 * proves is the full stack end to end — SPA to API across origins — and it fixes
 * the async-state vocabulary (skeleton, cold start, error with retry) that every
 * later screen reuses.
 */

interface HealthResponse {
  status: 'ok' | 'degraded'
  checks: {
    database: { ok: boolean; error?: string }
    queue: { pending: number | null }
  }
}

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()

  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}
      className="rounded-hair border-line text-ink-soft hover:border-line-strong hover:text-ink border px-2.5 py-1 font-mono text-xs transition-colors"
    >
      {theme === 'dark' ? 'light' : 'dark'}
    </button>
  )
}

function StatusSkeleton() {
  return (
    <div className="space-y-2.5" aria-hidden="true">
      <div className="rounded-hair bg-sunken h-3 w-40 animate-pulse" />
      <div className="rounded-hair bg-sunken h-3 w-28 animate-pulse" />
    </div>
  )
}

function ApiStatus() {
  const { data, error, isPending, isFetching, refetch } = useQuery({
    queryKey: ['health'],
    queryFn: () => apiFetch<HealthResponse>('/health'),
  })

  if (isPending) {
    return <StatusSkeleton />
  }

  if (error) {
    const isColdStart = error instanceof ApiError && error.kind === 'timeout'

    return (
      <div className="space-y-3">
        <p className="text-ink-soft text-sm">
          {isColdStart
            ? 'The API is waking up. Free-tier servers sleep when idle; this usually takes a few seconds.'
            : error instanceof ApiError
              ? error.message
              : 'Something went wrong reaching the API.'}
        </p>
        <button
          type="button"
          onClick={() => void refetch()}
          disabled={isFetching}
          className="rounded-hair bg-opportunity text-canvas hover:bg-opportunity-hover px-3 py-1.5 text-sm font-medium transition-colors disabled:opacity-60"
        >
          {isFetching ? 'Retrying' : 'Try again'}
        </button>
      </div>
    )
  }

  const healthy = data.status === 'ok'

  return (
    <dl className="space-y-2 text-sm">
      <div className="flex items-baseline justify-between gap-6">
        <dt className="text-ink-soft">API</dt>
        <dd className={`tabular ${healthy ? 'text-opportunity' : 'text-orphan'}`}>{data.status}</dd>
      </div>
      <div className="flex items-baseline justify-between gap-6">
        <dt className="text-ink-soft">Database</dt>
        <dd className={`tabular ${data.checks.database.ok ? 'text-opportunity' : 'text-orphan'}`}>
          {data.checks.database.ok ? 'connected' : 'unreachable'}
        </dd>
      </div>
      <div className="flex items-baseline justify-between gap-6">
        <dt className="text-ink-soft">Queued jobs</dt>
        <dd className="tabular text-ink">{data.checks.queue.pending ?? '—'}</dd>
      </div>
    </dl>
  )
}

function Shell() {
  return (
    <div className="bg-canvas min-h-dvh">
      <header className="border-line border-b">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
          <span className="font-display text-ink text-xl font-semibold tracking-tight">
            Linkweaver
          </span>
          <ThemeToggle />
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-6 py-16">
        <h1 className="font-display text-ink max-w-xl text-3xl leading-tight font-semibold sm:text-4xl">
          Find the internal links your site is missing.
        </h1>
        <p className="text-ink-soft mt-4 max-w-lg">
          Phase 0 scaffold. The crawler, link graph and suggestion review arrive in the phases that
          follow.
        </p>

        <section className="border-line bg-surface rounded-panel mt-12 max-w-sm border p-5">
          <h2 className="text-ink-faint mb-4 font-mono text-xs">System status</h2>
          <ApiStatus />
        </section>
      </main>
    </div>
  )
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Shell />} />
    </Routes>
  )
}
