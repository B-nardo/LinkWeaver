import { Link, useNavigate, useParams } from 'react-router-dom'

import { Button, ErrorState, Skeleton, StatusBadge } from '@/components/primitives'
import { useDeleteProject, useProject, useProjectStatus } from '@/lib/projects'
import type { Progress, ProjectStatus } from '@/types'

/** The pipeline in order, so progress can be shown as stages rather than a number. */
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

function Stat({ label, value }: { label: string; value: number | string }) {
  return (
    <div>
      <dt className="text-ink-soft text-sm">{label}</dt>
      <dd className="tabular font-display text-ink mt-0.5 text-2xl">{value}</dd>
    </div>
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

  if (project.isPending) {
    return (
      <div className="mx-auto max-w-4xl space-y-6 px-6 py-12">
        <Skeleton className="h-9 w-72" />
        <Skeleton className="h-32 w-full" />
      </div>
    )
  }

  if (project.error !== null) {
    return (
      <div className="mx-auto max-w-4xl px-6 py-12">
        <ErrorState error={project.error} onRetry={() => void project.refetch()} />
      </div>
    )
  }

  const current = live.data ?? project.data
  const status = current.status
  const progress = current.progress

  return (
    <div className="mx-auto max-w-4xl px-6 py-12">
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
        <StatusBadge status={status} label={current.status_label} />
      </div>

      {status === 'failed' && current.error_message !== null && (
        <div role="alert" className="rounded-panel border-orphan/40 bg-orphan-wash mt-8 border p-5">
          <p className="text-orphan font-medium">This audit could not be completed</p>
          <p className="text-ink mt-1.5 text-sm">{current.error_message}</p>
        </div>
      )}

      <div className="mt-8">
        <PipelineProgress status={status} progress={progress} />
      </div>

      <dl className="mt-10 grid grid-cols-2 gap-8 sm:grid-cols-3">
        <Stat label="Pages found" value={progress.pages_found} />
        <Stat label="Pages crawled" value={progress.pages_crawled} />
        <Stat label="Orphans" value={status === 'done' ? '—' : '·'} />
      </dl>

      <p className="text-ink-faint mt-3 max-w-prose text-sm">
        Orphan detection, the link graph and suggestions arrive in the next phases. The crawl above
        is what feeds them.
      </p>

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
