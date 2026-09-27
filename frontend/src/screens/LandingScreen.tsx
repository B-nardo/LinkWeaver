import { Link } from 'react-router-dom'

import { LinkGraph } from '@/components/LinkGraph'
import { Skeleton } from '@/components/primitives'
import { useGraph } from '@/lib/analysis'
import { useAuth } from '@/lib/auth-context'
import { useDemoProject } from '@/lib/demo'
import { useTheme } from '@/lib/theme-context'

/**
 * The landing page.
 *
 * Shows the real demo graph rather than a screenshot: the graph is the product,
 * a screenshot goes stale, and a visitor who can drag the nodes around has
 * already understood what the tool does without reading anything.
 */

function Stat({ value, label, tone }: { value: number | string; label: string; tone?: 'orphan' }) {
  return (
    <div>
      <div
        className={`tabular font-display text-3xl ${tone === 'orphan' ? 'text-orphan' : 'text-ink'}`}
      >
        {value}
      </div>
      <div className="text-ink-soft mt-0.5 text-sm">{label}</div>
    </div>
  )
}

function DemoGraph() {
  const demo = useDemoProject()
  const graph = useGraph(demo.data?.id, demo.data !== undefined)

  if (demo.isPending || graph.isPending) {
    return <Skeleton className="h-[420px] w-full" />
  }

  // A server with no seeded demo, or one still waking up, should not leave a
  // broken frame on the landing page — the page reads fine without it.
  if (demo.error !== null || graph.error !== null || graph.data === undefined) {
    return (
      <div className="border-line rounded-panel text-ink-faint border border-dashed p-12 text-center text-sm">
        The live demo is not available on this server right now.
      </div>
    )
  }

  return (
    <div>
      <dl className="border-line mb-6 grid grid-cols-2 gap-6 border-b pb-6 sm:grid-cols-4">
        <Stat value={graph.data.summary.pages} label="Pages crawled" />
        <Stat value={graph.data.summary.orphans} label="Orphans found" tone="orphan" />
        <Stat value={graph.data.summary.weak} label="Weakly linked" />
        <Stat value={graph.data.summary.edges} label="Internal links" />
      </dl>

      <LinkGraph graph={graph.data} selectedId={null} onSelect={() => undefined} height={420} />

      <p className="text-ink-faint mt-3 text-xs">
        A real audit of a fictional insurance brokerage. Drag a node, or scroll to zoom. Ringed dots
        are pages nothing links to.
      </p>
    </div>
  )
}

function Step({ number, title, children }: { number: string; title: string; children: string }) {
  return (
    <div>
      <div className="text-ink-faint font-mono text-xs">{number}</div>
      <h3 className="font-display text-ink mt-1 text-lg">{title}</h3>
      <p className="text-ink-soft mt-1.5 text-sm leading-relaxed">{children}</p>
    </div>
  )
}

export function LandingScreen() {
  const { theme, toggleTheme } = useTheme()
  const { user } = useAuth()
  const demo = useDemoProject()

  return (
    <div className="bg-canvas min-h-dvh">
      <header className="border-line border-b">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
          <span className="font-display text-ink text-xl font-semibold">Linkweaver</span>
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={toggleTheme}
              aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}
              className="rounded-hair border-line text-ink-soft hover:border-line-strong hover:text-ink border px-2.5 py-1 font-mono text-xs transition-colors"
            >
              {theme === 'dark' ? 'light' : 'dark'}
            </button>
            <Link
              to={user === null ? '/login' : '/projects'}
              className="text-ink-soft hover:text-ink text-sm"
            >
              {user === null ? 'Sign in' : 'Your audits'}
            </Link>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-6 py-16 sm:py-24">
        <h1 className="font-display text-ink max-w-3xl text-4xl leading-[1.1] font-semibold sm:text-5xl">
          Every site has pages nothing links to.
        </h1>

        <p className="text-ink-soft mt-5 max-w-xl text-lg leading-relaxed">
          Linkweaver crawls your sitemap, reads the main content of every page, and finds the ones
          your own site never links to — then suggests where to link them from, using anchor text
          that already exists word for word on the page.
        </p>

        <div className="mt-8 flex flex-wrap items-center gap-4">
          <Link
            to={demo.data === undefined ? '/login' : `/projects/${demo.data.id}`}
            className="bg-opportunity text-canvas hover:bg-opportunity-hover rounded-hair px-5 py-2.5 text-sm font-medium transition-colors"
          >
            Open the live demo
          </Link>
          <span className="text-ink-faint text-sm">No account needed.</span>
        </div>

        <section className="mt-16" aria-label="Live demo">
          <DemoGraph />
        </section>

        <section className="mt-20 grid gap-10 sm:grid-cols-3">
          <Step number="01" title="Crawl">
            Give it a sitemap URL. It fetches each page, extracts the main content, and records the
            links that sit inside that content — never the navigation.
          </Step>
          <Step number="02" title="Map">
            Pages with no in-content links pointing at them are orphans. The graph shows them as
            what they are: disconnected.
          </Step>
          <Step number="03" title="Suggest">
            Embeddings find pages that are topically related but unlinked. Every suggested anchor is
            checked to appear verbatim on the source page before you ever see it.
          </Step>
        </section>

        <section className="border-line mt-20 border-t pt-10">
          <h2 className="font-display text-ink text-xl">Why the navigation does not count</h2>
          <p className="text-ink-soft mt-3 max-w-2xl text-sm leading-relaxed">
            A footer link appears on every page of a site. If those counted, every page would look
            perfectly well linked and no orphan would ever surface — which is why most internal link
            tools quietly find nothing. Linkweaver counts only links written into the body of a
            page, because those are the ones that were an editorial decision.
          </p>
        </section>
      </main>

      <footer className="border-line mt-12 border-t">
        <div className="text-ink-faint mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-8 text-xs">
          <span>Linkweaver — internal link opportunity finder.</span>
          <Link to="/login" className="hover:text-ink">
            Sign in
          </Link>
        </div>
      </footer>
    </div>
  )
}
