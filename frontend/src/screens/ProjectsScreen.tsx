import { useState } from 'react'
import { Link } from 'react-router-dom'

import {
  Button,
  EmptyState,
  ErrorState,
  Field,
  Skeleton,
  StatusBadge,
} from '@/components/primitives'
import { ApiError } from '@/lib/api'
import { useCreateProject, useProjects } from '@/lib/projects'
import type { Project } from '@/types'

function NewProjectForm({ onDone }: { onDone: () => void }) {
  const [form, setForm] = useState({ name: '', sitemap_url: '', skip_taxonomies: true })
  const mutation = useCreateProject()

  const fieldErrors =
    mutation.error instanceof ApiError ? (mutation.error.validationErrors ?? {}) : {}

  return (
    <form
      className="rounded-panel border-line bg-surface space-y-5 border p-6"
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate(form, { onSuccess: onDone })
      }}
    >
      <Field
        label="Project name"
        required
        placeholder="Example Brokers"
        value={form.name}
        error={fieldErrors.name?.[0]}
        onChange={(e) => setForm({ ...form, name: e.target.value })}
      />

      <Field
        label="Sitemap URL"
        type="url"
        required
        placeholder="https://example.com/sitemap.xml"
        hint="A sitemap index works too — child sitemaps are followed automatically."
        value={form.sitemap_url}
        error={fieldErrors.sitemap_url?.[0]}
        onChange={(e) => setForm({ ...form, sitemap_url: e.target.value })}
      />

      <label className="text-ink flex items-start gap-2.5 text-sm">
        <input
          type="checkbox"
          checked={form.skip_taxonomies}
          onChange={(e) => setForm({ ...form, skip_taxonomies: e.target.checked })}
          className="mt-0.5"
        />
        <span>
          Skip category, tag and author archives
          <span className="text-ink-faint block text-xs">
            These aggregate other pages rather than being destinations, and they distort the link
            graph.
          </span>
        </span>
      </label>

      <div className="flex gap-2.5">
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? 'Starting' : 'Start audit'}
        </Button>
        <Button type="button" variant="quiet" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

function ProjectRow({ project }: { project: Project }) {
  const { pages_crawled: crawled, pages_found: found } = project.progress

  return (
    <li>
      <Link
        to={`/projects/${project.id}`}
        className="border-line hover:bg-sunken flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 border-b py-4 transition-colors"
      >
        <div className="min-w-0">
          <p className="font-display text-ink truncate text-lg">{project.name}</p>
          <p className="text-ink-faint truncate font-mono text-xs">{project.sitemap_url}</p>
        </div>
        <div className="flex items-center gap-5">
          <span className="tabular text-ink-soft text-sm">
            {found === 0 ? '—' : `${crawled}/${found}`}
          </span>
          <StatusBadge status={project.status} label={project.status_label} />
        </div>
      </Link>
    </li>
  )
}

export function ProjectsScreen() {
  const [isCreating, setIsCreating] = useState(false)
  const { data, error, isPending, refetch } = useProjects()

  return (
    <div className="mx-auto max-w-4xl px-6 py-12">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="font-display text-ink text-3xl font-semibold">Audits</h1>
          <p className="text-ink-soft mt-1.5 text-sm">
            Each audit maps one site's internal links from its sitemap.
          </p>
        </div>
        {!isCreating && <Button onClick={() => setIsCreating(true)}>New audit</Button>}
      </div>

      {isCreating && (
        <div className="mt-8">
          <NewProjectForm onDone={() => setIsCreating(false)} />
        </div>
      )}

      <div className="mt-10">
        {isPending && (
          <div className="space-y-4">
            <Skeleton className="h-14 w-full" />
            <Skeleton className="h-14 w-full" />
            <Skeleton className="h-14 w-2/3" />
          </div>
        )}

        {error !== null && !isPending && (
          <ErrorState error={error} onRetry={() => void refetch()} />
        )}

        {data !== undefined && data.length === 0 && !isCreating && (
          <EmptyState
            title="No audits yet"
            description="Point Linkweaver at a sitemap and it will crawl the pages, read the main content of each one, and map how they link to each other."
            action={<Button onClick={() => setIsCreating(true)}>Start your first audit</Button>}
          />
        )}

        {data !== undefined && data.length > 0 && (
          <ul className="border-line border-t">
            {data.map((project) => (
              <ProjectRow key={project.id} project={project} />
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}
