import { useState } from 'react'

import { Button, EmptyState, ErrorState, Skeleton } from './primitives'
import { usePages } from '@/lib/analysis'
import type { PageClassification, PageQueryParams, ProjectPage } from '@/types'

const FILTERS: { value: PageQueryParams['filter']; label: string }[] = [
  { value: null, label: 'All pages' },
  { value: 'attention', label: 'Needs links' },
  { value: 'orphan', label: 'Orphans' },
  { value: 'weak', label: 'Weakly linked' },
  { value: 'linked', label: 'Well linked' },
]

export function ClassificationTag({ classification }: { classification: PageClassification }) {
  const style =
    classification === 'orphan'
      ? 'text-orphan bg-orphan-wash border-orphan/40'
      : classification === 'weak'
        ? 'text-opportunity bg-opportunity-wash border-opportunity/40'
        : 'text-ink-faint border-line bg-sunken'

  const label =
    classification === 'orphan' ? 'Orphan' : classification === 'weak' ? 'Weak' : 'Linked'

  return (
    <span className={`rounded-pill border px-2 py-0.5 font-mono text-xs ${style}`}>{label}</span>
  )
}

function SortButton({
  label,
  active,
  direction,
  onClick,
  align = 'left',
}: {
  label: string
  active: boolean
  direction: 'asc' | 'desc'
  onClick: () => void
  align?: 'left' | 'right'
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
      className={`flex w-full items-center gap-1 text-xs font-medium ${
        align === 'right' ? 'justify-end' : ''
      } ${active ? 'text-ink' : 'text-ink-faint hover:text-ink-soft'}`}
    >
      {label}
      <span aria-hidden="true" className="font-mono">
        {active ? (direction === 'asc' ? '↑' : '↓') : ''}
      </span>
    </button>
  )
}

function Row({ page, onSelect }: { page: ProjectPage; onSelect?: (page: ProjectPage) => void }) {
  const path = (() => {
    try {
      return new URL(page.normalized_url).pathname
    } catch {
      return page.normalized_url
    }
  })()

  return (
    <tr className="border-line hover:bg-sunken border-b transition-colors">
      <td className="py-3 pr-4">
        {onSelect === undefined ? (
          <div className="min-w-0">
            <p className="text-ink truncate text-sm">{page.title ?? path}</p>
            <p className="text-ink-faint truncate font-mono text-xs">{path}</p>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => onSelect(page)}
            className="block max-w-full min-w-0 text-left"
          >
            <p className="text-ink truncate text-sm">{page.title ?? path}</p>
            <p className="text-ink-faint truncate font-mono text-xs">{path}</p>
          </button>
        )}
      </td>
      <td className="tabular text-ink py-3 pr-4 text-right text-sm">{page.inbound_count}</td>
      <td className="tabular text-ink-soft py-3 pr-4 text-right text-sm">{page.outbound_count}</td>
      <td className="tabular text-ink-faint hidden py-3 pr-4 text-right text-sm sm:table-cell">
        {page.word_count}
      </td>
      <td className="py-3 text-right">
        {page.crawl_error !== null ? (
          <span className="text-ink-faint border-line rounded-pill border px-2 py-0.5 font-mono text-xs">
            Not crawled
          </span>
        ) : (
          <ClassificationTag classification={page.classification} />
        )}
      </td>
    </tr>
  )
}

/**
 * The pages table.
 *
 * Doubles as the accessible alternative to the link graph, which is why it
 * lives in a component rather than only on its own route: the same data, in a
 * form that can be read, sorted and navigated by keyboard.
 */
export function PagesTable({
  projectId,
  onSelect,
  initialFilter = null,
}: {
  projectId: string | undefined
  onSelect?: (page: ProjectPage) => void
  initialFilter?: PageQueryParams['filter']
}) {
  const [params, setParams] = useState<PageQueryParams>({
    filter: initialFilter,
    sort: 'inbound',
    direction: 'asc',
    page: 1,
  })

  const { data, error, isPending, refetch } = usePages(projectId, params)

  const sortBy = (sort: NonNullable<PageQueryParams['sort']>) => {
    setParams((current) => ({
      ...current,
      sort,
      direction: current.sort === sort && current.direction === 'asc' ? 'desc' : 'asc',
      page: 1,
    }))
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2">
        {FILTERS.map((filter) => {
          const active = (params.filter ?? null) === (filter.value ?? null)

          return (
            <button
              key={filter.label}
              type="button"
              aria-pressed={active}
              onClick={() => setParams((c) => ({ ...c, filter: filter.value, page: 1 }))}
              className={`rounded-pill border px-3 py-1 text-xs transition-colors ${
                active
                  ? 'border-ink bg-ink text-canvas'
                  : 'border-line text-ink-soft hover:border-line-strong hover:text-ink'
              }`}
            >
              {filter.label}
            </button>
          )
        })}
      </div>

      {isPending && (
        <div className="space-y-3">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-4/5" />
        </div>
      )}

      {error !== null && !isPending && <ErrorState error={error} onRetry={() => void refetch()} />}

      {data !== undefined && data.data.length === 0 && (
        <EmptyState
          title="Nothing matches that filter"
          description={
            params.filter === 'orphan'
              ? 'No orphan pages — every page in this sitemap has at least one in-content link pointing at it.'
              : 'Try a different filter to see the rest of the pages in this audit.'
          }
          action={
            <Button variant="quiet" onClick={() => setParams((c) => ({ ...c, filter: null }))}>
              Show all pages
            </Button>
          }
        />
      )}

      {data !== undefined && data.data.length > 0 && (
        <>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[34rem] border-collapse">
              <caption className="sr-only">
                Pages in this audit with their inbound and outbound in-content link counts
              </caption>
              <thead>
                <tr className="border-line border-b">
                  <th scope="col" className="py-2 pr-4 text-left">
                    <SortButton
                      label="Page"
                      active={params.sort === 'title'}
                      direction={params.direction ?? 'asc'}
                      onClick={() => sortBy('title')}
                    />
                  </th>
                  <th scope="col" className="w-20 py-2 pr-4">
                    <SortButton
                      label="In"
                      align="right"
                      active={params.sort === 'inbound'}
                      direction={params.direction ?? 'asc'}
                      onClick={() => sortBy('inbound')}
                    />
                  </th>
                  <th scope="col" className="w-20 py-2 pr-4">
                    <SortButton
                      label="Out"
                      align="right"
                      active={params.sort === 'outbound'}
                      direction={params.direction ?? 'asc'}
                      onClick={() => sortBy('outbound')}
                    />
                  </th>
                  <th scope="col" className="hidden w-24 py-2 pr-4 sm:table-cell">
                    <SortButton
                      label="Words"
                      align="right"
                      active={params.sort === 'words'}
                      direction={params.direction ?? 'asc'}
                      onClick={() => sortBy('words')}
                    />
                  </th>
                  <th
                    scope="col"
                    className="text-ink-faint w-28 py-2 text-right text-xs font-medium"
                  >
                    Status
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((page) => (
                  <Row key={page.id} page={page} onSelect={onSelect} />
                ))}
              </tbody>
            </table>
          </div>

          {data.meta.last_page > 1 && (
            <div className="mt-6 flex items-center justify-between">
              <p className="tabular text-ink-faint text-xs">
                Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total} pages
              </p>
              <div className="flex gap-2">
                <Button
                  variant="quiet"
                  disabled={data.meta.current_page <= 1}
                  onClick={() => setParams((c) => ({ ...c, page: (c.page ?? 1) - 1 }))}
                  className="px-2.5 py-1 text-xs"
                >
                  Previous
                </Button>
                <Button
                  variant="quiet"
                  disabled={data.meta.current_page >= data.meta.last_page}
                  onClick={() => setParams((c) => ({ ...c, page: (c.page ?? 1) + 1 }))}
                  className="px-2.5 py-1 text-xs"
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
