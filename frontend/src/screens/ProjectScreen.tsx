import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import { LinkGraph } from '@/components/LinkGraph'
import { NodePanel } from '@/components/NodePanel'
import { PagesTable } from '@/components/PagesTable'
import { Button, ErrorState, Skeleton, StatusBadge } from '@/components/primitives'
import { useGraph } from '@/lib/analysis'
import { useDeleteProject, useProject, useProjectStatus } from '@/lib/projects'
import type { GraphPayload, Progress, ProjectStatus } from '@/types'

/** The pipeline in order, so progress reads as stages rather than a number. */
const STAGES: { key: ProjectStatus; label: string }[] = [
  { key: 'crawling', label: 'Crawl' },
  { key: 'embedding', label: 'Embed' },
  { key: 'analyzing', label: 'Analyse' },
  { key: 'done', label: 'Ready' },
]

function stageIndex(status: ProjectStatus): number {
  if (status === 'pending') return -1
  return STAGES.findIndex((stage) => stage.key === status)
}

function PipelineProgress({ status, progress }: { status: ProjectStatus; progress: Progress }) {
  const current = stageIndex(status)
  const { pages_crawled: crawled, pages_found: found } = progress
  const percent = found === 0 ? 0 : Math.round((crawled / found) * 100)

  return (
    <div className="rounded-panel border-line bg-surface border p-6">
      <ol className="flex flex-wrap gap-x-8 gap-y-3">
        {STAGES.map((stage, index) => {
          const state =
            status === 'failed' && index === current
              ? 'failed'
              : index < current || status === 'done'
                ? 'complete'
                : index === current
                  ? 'active'
                  : 'pending'

          return (
            <li key={stage.key} className="flex items-center gap-2">
              <span
                aria-hidden="true"
                className={`rounded-pill size-2 ${
                  state === 'complete'
                    ? 'bg-opportunity'
                    : state === 'active'
                      ? 'bg-opportunity animate-pulse'
                      : state === 'failed'
                        ? 'bg-orphan'
                        : 'bg-line-strong'
                }`}
              />
              <span
                className={`font-mono text-xs ${state === 'pending' ? 'text-ink-faint' : 'text-ink'}`}
              >
                {stage.label}
              </span>
            </li>
          )
        })}
      </ol>

      {status === 'crawling' && (
        <div className="mt-6">
          <div className="flex items-baseline justify-between">
            <span className="text-ink-soft text-sm">Pages crawled</span>
            <span className="tabular text-ink text-sm">
              {crawled} / {found === 0 ? '?' : found}
            </span>
          </div>
          <div
            className="rounded-pill bg-sunken mt-2 h-1 w-full overflow-hidden"
            role="progressbar"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label="Crawl progress"
          >
            <div
              className="bg-opportunity h-full transition-[width] duration-500"
              style={{ width: `${percent}%` }}
            />
          </div>
        </div>
      )}
    </div>
  )
}

function Stat({
  label,
  value,
  tone = 'ink',
}: {
  label: string
  value: number | string
  tone?: 'ink' | 'orphan' | 'opportunity'
}) {
  const color =
    tone === 'orphan' ? 'text-orphan' : tone === 'opportunity' ? 'text-opportunity' : 'text-ink'

  return (
    <div>
      <dt className="text-ink-soft text-sm">{label}</dt>
      <dd className={`tabular font-display mt-0.5 text-2xl ${color}`}>{value}</dd>
    </div>
  )
}

function Analysis({ projectId, graph }: { projectId: string; graph: GraphPayload }) {
  const [view, setView] = useState<'graph' | 'table'>('graph')
  const [selectedId, setSelectedId] = useState<number | null>(null)

  const selected = graph.nodes.find((node) => node.id === selectedId) ?? null

  return (
    <>
      <dl className="mt-10 grid grid-cols-2 gap-8 sm:grid-cols-4">
        <Stat label="Pages" value={graph.summary.pages} />
        <Stat label="Orphans" value={graph.summary.orphans} tone="orphan" />
        <Stat label="Weakly linked" value={graph.summary.weak} tone="opportunity" />
        <Stat label="Internal links" value={graph.summary.edges} />
      </dl>

      {graph.summary.uncrawled > 0 && (
        <p className="border-line bg-sunken text-ink-soft rounded-hair mt-6 border p-3 text-sm">
          {graph.summary.uncrawled} {graph.summary.uncrawled === 1 ? 'page' : 'pages'} could not be
          crawled, so their outgoing links are unknown. Some pages may be shown as orphans when
          something links to them from a page we could not read.
        </p>
      )}

      <div className="mt-10 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="font-display text-ink text-xl">Link structure</h2>
          <p className="text-ink-soft mt-1 text-sm">
            Each dot is a page, sized by how many in-content links point at it. Ringed dots are
            orphans.
          </p>
        </div>

        <div
          role="group"
          aria-label="View link structure as"
          className="border-line rounded-hair flex border p-0.5"
        >
          {(['graph', 'table'] as const).map((option) => (
            <button
              key={option}
              type="button"
              aria-pressed={view === option}
              onClick={() => setView(option)}
              className={`rounded-hair px-3 py-1 font-mono text-xs capitalize transition-colors ${
                view === option ? 'bg-ink text-canvas' : 'text-ink-soft hover:text-ink'
              }`}
            >
              {option}
            </button>
          ))}
        </div>
      </div>

      <div className="mt-5">
        {view === 'graph' ? (
          <div className="grid gap-5 lg:grid-cols-[1fr_20rem]">
            <LinkGraph
              graph={graph}
              selectedId={selectedId}
              onSelect={(node) => setSelectedId(node.id)}
            />
            {selected !== null ? (
              <NodePanel graph={graph} node={selected} onClose={() => setSelectedId(null)} />
            ) : (
              <div className="border-line rounded-panel text-ink-faint hidden border border-dashed p-5 text-sm lg:block">
                Select a page in the graph to see what links to it.
              </div>
            )}
          </div>
        ) : (
          <PagesTable projectId={projectId} onSelect={(page) => setSelectedId(page.id)} />
        )}
      </div>
    </>
  )
}

export function ProjectScreen() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const project = useProject(id)
  const remove = useDeleteProject()

  // Only poll while there is something to watch; the query stops itself once
  // the pipeline reports a terminal state.
  const live = useProjectStatus(id, project.data !== undefined && !project.data.is_terminal)

  const current = live.data ?? project.data
  const isDone = current?.status === 'done'

  // The structure is only meaningful once the crawl has finished, so the graph
  // is not requested until then.
  const graph = useGraph(id, isDone)

  if (project.isPending) {
    return (
      <div className="mx-auto max-w-5xl space-y-6 px-6 py-12">
        <Skeleton className="h-9 w-72" />
        <Skeleton className="h-32 w-full" />
      </div>
    )
  }

  if (project.error !== null || current === undefined) {
    return (
      <div className="mx-auto max-w-5xl px-6 py-12">
        <ErrorState error={project.error} onRetry={() => void project.refetch()} />
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-5xl px-6 py-12">
      <Link to="/projects" className="text-ink-soft hover:text-ink font-mono text-xs">
        ← Audits
      </Link>

      <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="font-display text-ink text-3xl font-semibold">{project.data.name}</h1>
          <p className="text-ink-faint mt-1 truncate font-mono text-xs">
            {project.data.sitemap_url}
          </p>
        </div>
        <StatusBadge status={current.status} label={current.status_label} />
      </div>

      {current.status === 'failed' && current.error_message !== null && (
        <div role="alert" className="rounded-panel border-orphan/40 bg-orphan-wash mt-8 border p-5">
          <p className="text-orphan font-medium">This audit could not be completed</p>
          <p className="text-ink mt-1.5 text-sm">{current.error_message}</p>
        </div>
      )}

      <div className="mt-8">
        <PipelineProgress status={current.status} progress={current.progress} />
      </div>

      {isDone && graph.isPending && (
        <div className="mt-10 space-y-6">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-[520px] w-full" />
        </div>
      )}

      {isDone && graph.error !== null && (
        <div className="mt-10">
          <ErrorState error={graph.error} onRetry={() => void graph.refetch()} />
        </div>
      )}

      {isDone && graph.data !== undefined && id !== undefined && (
        <Analysis projectId={id} graph={graph.data} />
      )}

      {!project.data.is_demo && (
        <div className="border-line mt-14 border-t pt-6">
          <Button
            variant="danger"
            disabled={remove.isPending}
            onClick={() => {
              if (id === undefined) return
              remove.mutate(id, { onSuccess: () => void navigate('/projects') })
            }}
          >
            {remove.isPending ? 'Deleting' : 'Delete this audit'}
          </Button>
          <p className="text-ink-faint mt-2 text-xs">
            Removes the project and every page and link it collected.
          </p>
        </div>
      )}
    </div>
  )
}
