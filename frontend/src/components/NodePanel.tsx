import { useEffect, useMemo, useRef } from 'react'

import { ClassificationTag } from './PagesTable'
import type { GraphNode, GraphPayload } from '@/types'

/**
 * Detail for one page in the graph: what points at it, and what it points at.
 *
 * Neighbours are derived from the graph payload already in memory rather than
 * fetched, so clicking around the graph costs nothing and works offline once
 * the graph has loaded.
 */
export function NodePanel({
  graph,
  node,
  onClose,
}: {
  graph: GraphPayload
  node: GraphNode
  onClose: () => void
}) {
  const closeRef = useRef<HTMLButtonElement | null>(null)

  const byId = useMemo(() => new Map(graph.nodes.map((n) => [n.id, n])), [graph.nodes])

  const { inbound, outbound } = useMemo(() => {
    const inboundNodes: GraphNode[] = []
    const outboundNodes: GraphNode[] = []

    for (const edge of graph.edges) {
      if (edge.target === node.id) {
        const source = byId.get(edge.source)
        if (source !== undefined) inboundNodes.push(source)
      }

      if (edge.source === node.id) {
        const target = byId.get(edge.target)
        if (target !== undefined) outboundNodes.push(target)
      }
    }

    return { inbound: inboundNodes, outbound: outboundNodes }
  }, [graph.edges, byId, node.id])

  // Move focus to the panel when it opens, so a keyboard user is not left at
  // the graph canvas with no idea that anything appeared.
  useEffect(() => {
    closeRef.current?.focus()
  }, [node.id])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', onKeyDown)

    return () => {
      window.removeEventListener('keydown', onKeyDown)
    }
  }, [onClose])

  const path = (() => {
    try {
      return new URL(node.url).pathname
    } catch {
      return node.url
    }
  })()

  return (
    <aside
      aria-label={`Details for ${node.title}`}
      className="rounded-panel border-line bg-surface border p-5"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="font-display text-ink text-lg leading-snug">{node.title}</h3>
          <p className="text-ink-faint mt-0.5 truncate font-mono text-xs">{path}</p>
        </div>
        <button
          ref={closeRef}
          type="button"
          onClick={onClose}
          aria-label="Close details"
          className="text-ink-faint hover:text-ink rounded-hair px-1.5 font-mono text-sm"
        >
          ✕
        </button>
      </div>

      <div className="mt-4 flex items-center gap-3">
        <ClassificationTag classification={node.classification} />
        <span className="tabular text-ink-soft text-xs">
          {node.inbound} in · {node.outbound} out
        </span>
      </div>

      {node.classification === 'orphan' && (
        <p className="border-orphan/40 bg-orphan-wash text-ink rounded-hair mt-4 border p-3 text-sm">
          Nothing on this site links to this page from within its content. Search engines and
          readers can only reach it from the sitemap.
        </p>
      )}

      <NeighbourList
        heading="Linked from"
        empty="No in-content links point here."
        nodes={inbound}
      />
      <NeighbourList heading="Links to" empty="This page links to nothing else." nodes={outbound} />

      <p className="text-ink-faint mt-5 text-xs">
        Suggested links for this page arrive in a later phase.
      </p>
    </aside>
  )
}

function NeighbourList({
  heading,
  empty,
  nodes,
}: {
  heading: string
  empty: string
  nodes: GraphNode[]
}) {
  return (
    <section className="mt-5">
      <h4 className="text-ink-soft mb-2 text-sm font-medium">
        {heading} <span className="tabular text-ink-faint">({nodes.length})</span>
      </h4>
      {nodes.length === 0 ? (
        <p className="text-ink-faint text-xs">{empty}</p>
      ) : (
        <ul className="border-line divide-line divide-y border-t">
          {nodes.slice(0, 12).map((neighbour) => (
            <li key={neighbour.id} className="text-ink truncate py-1.5 text-sm">
              {neighbour.title}
            </li>
          ))}
          {nodes.length > 12 && (
            <li className="text-ink-faint py-1.5 text-xs">and {nodes.length - 12} more</li>
          )}
        </ul>
      )}
    </section>
  )
}
