import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { Button, EmptyState, ErrorState, Skeleton } from '@/components/primitives'
import { getAuthToken } from '@/lib/api'
import { useBulkDecide, useDecideSuggestion, useSuggestions } from '@/lib/suggestions'
import type { Suggestion, SuggestionQueryParams, SuggestionStatus } from '@/types'

const FILTERS: { value: SuggestionStatus | null; label: string }[] = [
  { value: 'pending', label: 'To review' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: null, label: 'All' },
]

/**
 * Renders the context sentence with the anchor marked inside it.
 *
 * Split on the literal anchor rather than highlighted with a regex, because the
 * anchor is arbitrary user-adjacent text and building a pattern from it is how
 * you get an injection or a catastrophic backtrack.
 */
function HighlightedSentence({ sentence, anchor }: { sentence: string; anchor: string }) {
  const index = sentence.toLowerCase().indexOf(anchor.toLowerCase())

  if (index === -1) {
    return <span className="text-ink-soft">{sentence}</span>
  }

  return (
    <span className="text-ink-soft">
      {sentence.slice(0, index)}
      <mark className="bg-opportunity-wash text-opportunity rounded-hair px-1 font-medium">
        {sentence.slice(index, index + anchor.length)}
      </mark>
      {sentence.slice(index + anchor.length)}
    </span>
  )
}

function StatusPill({ status, label }: { status: SuggestionStatus; label: string }) {
  const tone =
    status === 'approved'
      ? 'text-opportunity border-opportunity/40 bg-opportunity-wash'
      : status === 'rejected'
        ? 'text-ink-faint border-line bg-sunken line-through'
        : 'text-ink-soft border-line'

  return (
    <span className={`rounded-pill border px-2 py-0.5 font-mono text-xs ${tone}`}>{label}</span>
  )
}

function Row({
  suggestion,
  isFocused,
  isSelected,
  onFocus,
  onToggleSelect,
  onDecide,
}: {
  suggestion: Suggestion
  isFocused: boolean
  isSelected: boolean
  onFocus: () => void
  onToggleSelect: () => void
  onDecide: (status: 'approved' | 'rejected') => void
}) {
  const decided = suggestion.status === 'approved' || suggestion.status === 'rejected'

  return (
    <li
      onFocus={onFocus}
      onMouseEnter={onFocus}
      className={`border-line border-b px-4 py-4 transition-colors ${
        isFocused ? 'bg-sunken' : ''
      } ${suggestion.status === 'rejected' ? 'opacity-55' : ''}`}
    >
      <div className="flex items-start gap-3">
        <input
          type="checkbox"
          checked={isSelected}
          onChange={onToggleSelect}
          aria-label={`Select suggestion linking to ${suggestion.target?.title ?? 'page'}`}
          className="mt-1"
        />

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-x-2 text-sm">
            <span className="text-ink-faint">Link from</span>
            <span className="text-ink font-medium">{suggestion.source?.title ?? 'Untitled'}</span>
            <span className="text-ink-faint">to</span>
            <span className="text-ink font-medium">{suggestion.target?.title ?? 'Untitled'}</span>
          </div>

          {suggestion.context_sentence !== null && (
            <p className="mt-2 text-sm leading-relaxed">
              <HighlightedSentence
                sentence={suggestion.context_sentence}
                anchor={suggestion.anchor_text}
              />
            </p>
          )}

          <div className="text-ink-faint mt-2.5 flex flex-wrap items-center gap-x-5 gap-y-1 font-mono text-xs">
            <span className="tabular">priority {suggestion.priority_score.toFixed(3)}</span>
            <span className="tabular">similarity {suggestion.similarity.toFixed(3)}</span>
            <span className="truncate">{suggestion.target?.url}</span>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-2">
          {decided ? (
            <StatusPill status={suggestion.status} label={suggestion.status_label} />
          ) : (
            <>
              <Button
                onClick={() => onDecide('approved')}
                className="px-2.5 py-1 text-xs"
                title="Approve (A)"
              >
                Approve
              </Button>
              <Button
                variant="quiet"
                onClick={() => onDecide('rejected')}
                className="px-2.5 py-1 text-xs"
                title="Reject (R)"
              >
                Reject
              </Button>
            </>
          )}
        </div>
      </div>
    </li>
  )
}

export function SuggestionsScreen() {
  const { id } = useParams<{ id: string }>()
  const [params, setParams] = useState<SuggestionQueryParams>({ status: 'pending', page: 1 })
  const [focusedIndex, setFocusedIndex] = useState(0)
  const [selected, setSelected] = useState<Set<number>>(new Set())

  // Changing filter or page resets the cursor here rather than in an effect:
  // the reset belongs to the event that caused it, not to a later render.
  const applyParams = (next: SuggestionQueryParams) => {
    setParams(next)
    setFocusedIndex(0)
  }

  const { data, error, isPending, refetch } = useSuggestions(id, params)
  const decide = useDecideSuggestion(id)
  const bulk = useBulkDecide(id)

  const rows = useMemo(() => data?.data ?? [], [data])

  const decideAt = useCallback(
    (index: number, status: 'approved' | 'rejected') => {
      const row = rows[index]
      if (row === undefined) return

      decide.mutate({ id: row.id, status })

      // Advance, so A/A/A works as a rhythm rather than needing a arrow press
      // between each decision.
      setFocusedIndex((current) => Math.min(current + 1, Math.max(rows.length - 1, 0)))
    },
    [rows, decide],
  )

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target
      const typing =
        target instanceof HTMLElement &&
        (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)

      if (typing || event.metaKey || event.ctrlKey || event.altKey) return

      if (event.key === 'a' || event.key === 'A') {
        event.preventDefault()
        decideAt(focusedIndex, 'approved')
      } else if (event.key === 'r' || event.key === 'R') {
        event.preventDefault()
        decideAt(focusedIndex, 'rejected')
      } else if (event.key === 'j' || event.key === 'ArrowDown') {
        event.preventDefault()
        setFocusedIndex((c) => Math.min(c + 1, Math.max(rows.length - 1, 0)))
      } else if (event.key === 'k' || event.key === 'ArrowUp') {
        event.preventDefault()
        setFocusedIndex((c) => Math.max(c - 1, 0))
      }
    }

    window.addEventListener('keydown', onKeyDown)

    return () => {
      window.removeEventListener('keydown', onKeyDown)
    }
  }, [focusedIndex, rows.length, decideAt])

  const toggleSelect = (suggestionId: number) => {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(suggestionId)) {
        next.delete(suggestionId)
      } else {
        next.add(suggestionId)
      }

      return next
    })
  }

  const exportCsv = () => {
    // The export is an authenticated GET, so it cannot be a plain link — the
    // bearer token would never be sent. Fetched, then handed to the browser.
    const token = getAuthToken()
    const base = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api').replace(/\/+$/, '')

    void fetch(`${base}/projects/${id ?? ''}/export.csv`, {
      headers: token === null ? {} : { Authorization: `Bearer ${token}` },
    })
      .then((response) => response.blob())
      .then((blob) => {
        const url = URL.createObjectURL(blob)
        const anchor = document.createElement('a')
        anchor.href = url
        anchor.download = `linkweaver-${id ?? 'export'}.csv`
        anchor.click()
        URL.revokeObjectURL(url)
      })
  }

  return (
    <div className="mx-auto max-w-4xl px-6 py-12">
      <Link to={`/projects/${id ?? ''}`} className="text-ink-soft hover:text-ink font-mono text-xs">
        ← Overview
      </Link>

      <div className="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="font-display text-ink text-3xl font-semibold">Suggested links</h1>
          <p className="text-ink-soft mt-1.5 text-sm">
            Each anchor below already appears word for word on the source page. Press{' '}
            <kbd className="border-line rounded-hair border px-1 font-mono text-xs">A</kbd> to
            approve, <kbd className="border-line rounded-hair border px-1 font-mono text-xs">R</kbd>{' '}
            to reject.
          </p>
        </div>
        <Button variant="quiet" onClick={exportCsv}>
          Export approved CSV
        </Button>
      </div>

      <div className="mt-8 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          {FILTERS.map((filter) => {
            const active = (params.status ?? null) === filter.value

            return (
              <button
                key={filter.label}
                type="button"
                aria-pressed={active}
                onClick={() => applyParams({ status: filter.value, page: 1 })}
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

        {selected.size > 0 && (
          <div className="flex items-center gap-2">
            <span className="tabular text-ink-soft text-xs">{selected.size} selected</span>
            <Button
              onClick={() => {
                bulk.mutate(
                  { ids: [...selected], status: 'approved' },
                  { onSuccess: () => setSelected(new Set()) },
                )
              }}
              className="px-2.5 py-1 text-xs"
            >
              Approve all
            </Button>
            <Button
              variant="quiet"
              onClick={() => {
                bulk.mutate(
                  { ids: [...selected], status: 'rejected' },
                  { onSuccess: () => setSelected(new Set()) },
                )
              }}
              className="px-2.5 py-1 text-xs"
            >
              Reject all
            </Button>
          </div>
        )}
      </div>

      <div className="mt-6">
        {isPending && (
          <div className="space-y-3">
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-24 w-full" />
            <Skeleton className="h-24 w-3/4" />
          </div>
        )}

        {error !== null && !isPending && (
          <ErrorState error={error} onRetry={() => void refetch()} />
        )}

        {data !== undefined && rows.length === 0 && (
          <EmptyState
            title={params.status === 'pending' ? 'Nothing left to review' : 'Nothing here'}
            description={
              params.status === 'pending'
                ? 'Every suggestion for this audit has been approved or rejected. Export the approved ones to get to work.'
                : 'No suggestions match this filter yet.'
            }
          />
        )}

        {data !== undefined && rows.length > 0 && (
          <>
            <ul className="border-line border-t">
              {rows.map((suggestion, index) => (
                <Row
                  key={suggestion.id}
                  suggestion={suggestion}
                  isFocused={index === focusedIndex}
                  isSelected={selected.has(suggestion.id)}
                  onFocus={() => setFocusedIndex(index)}
                  onToggleSelect={() => toggleSelect(suggestion.id)}
                  onDecide={(status) => decideAt(index, status)}
                />
              ))}
            </ul>

            {data.meta.last_page > 1 && (
              <div className="mt-6 flex items-center justify-between">
                <p className="tabular text-ink-faint text-xs">
                  Page {data.meta.current_page} of {data.meta.last_page} · {data.meta.total} total
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="quiet"
                    disabled={data.meta.current_page <= 1}
                    onClick={() => applyParams({ ...params, page: (params.page ?? 1) - 1 })}
                    className="px-2.5 py-1 text-xs"
                  >
                    Previous
                  </Button>
                  <Button
                    variant="quiet"
                    disabled={data.meta.current_page >= data.meta.last_page}
                    onClick={() => applyParams({ ...params, page: (params.page ?? 1) + 1 })}
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
    </div>
  )
}
