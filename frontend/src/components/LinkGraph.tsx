import { lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react'

import type { ForceGraphMethods } from 'react-force-graph-2d'

import { Skeleton } from './primitives'
import { useTheme } from '@/lib/theme-context'
import type { GraphNode, GraphPayload } from '@/types'

/**
 * The link graph.
 *
 * `react-force-graph-2d` and its canvas engine are several megabytes, and this
 * is the only screen that needs them, so the import is deferred until the graph
 * is actually shown.
 */
const ForceGraph2D = lazy(() => import('react-force-graph-2d'))

/** What the force layout mutates our nodes into once it has positioned them. */
interface PositionedNode extends GraphNode {
  x?: number
  y?: number
}

function cssVar(name: string, fallback: string): string {
  if (typeof window === 'undefined') return fallback

  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()

  return value === '' ? fallback : value
}

function useContainerWidth(): [React.RefObject<HTMLDivElement | null>, number] {
  const ref = useRef<HTMLDivElement | null>(null)
  const [width, setWidth] = useState(0)

  useEffect(() => {
    const element = ref.current
    if (element === null) return

    const observer = new ResizeObserver((entries) => {
      const entry = entries[0]
      if (entry !== undefined) setWidth(entry.contentRect.width)
    })

    observer.observe(element)

    return () => {
      observer.disconnect()
    }
  }, [])

  return [ref, width]
}

export function LinkGraph({
  graph,
  onSelect,
  selectedId,
  height = 520,
}: {
  graph: GraphPayload
  onSelect: (node: GraphNode) => void
  selectedId: number | null
  height?: number
}) {
  const [containerRef, width] = useContainerWidth()
  // The library's own handle type; imported as a type only, so the lazy
  // chunk boundary is preserved.
  const graphRef = useRef<ForceGraphMethods | undefined>(undefined)
  const { theme } = useTheme()

  // Canvas cannot read Tailwind classes, so the palette is pulled from the same
  // custom properties the rest of the interface uses. Re-read on theme change.
  const palette = useMemo(
    () => ({
      orphan: cssVar('--lw-orphan', '#ad4a1e'),
      opportunity: cssVar('--lw-opportunity', '#0f7a6c'),
      ink: cssVar('--lw-ink', '#1c1b18'),
      faint: cssVar('--lw-ink-faint', '#837b6f'),
      edge: cssVar('--lw-graph-edge', '#bdb3a2'),
      surface: cssVar('--lw-surface', '#ffffff'),
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- theme is the signal that the variables changed
    [theme],
  )

  // force-graph mutates the objects it is given (adding x/y), and treats a new
  // array identity as a brand-new simulation. Cloning once per payload keeps it
  // from re-running the layout on every render while leaving our data intact.
  const data = useMemo(
    () => ({
      nodes: graph.nodes.map((node) => ({ ...node })),
      links: graph.edges.map((edge) => ({ source: edge.source, target: edge.target })),
    }),
    [graph],
  )

  const colorFor = (node: PositionedNode): string =>
    node.classification === 'orphan'
      ? palette.orphan
      : node.classification === 'weak'
        ? palette.opportunity
        : palette.faint

  return (
    <div
      ref={containerRef}
      className="rounded-panel border-line bg-surface border"
      style={{ height }}
    >
      <Suspense fallback={<Skeleton className="h-full w-full" />}>
        {width > 0 && (
          <ForceGraph2D
            width={width}
            height={height}
            ref={graphRef}
            graphData={data}
            backgroundColor="rgba(0,0,0,0)"
            linkColor={() => palette.edge}
            linkDirectionalArrowLength={3}
            linkDirectionalArrowRelPos={1}
            cooldownTicks={120}
            // Orphans have no edges pulling them in, so the layout flings them
            // outwards and they end up cropped at the canvas edge — losing the
            // one thing the picture is meant to show. Fitting the view once the
            // simulation settles brings every node back into frame.
            onEngineStop={() => {
              graphRef.current?.zoomToFit(400, 48)
            }}
            onNodeClick={(node: object) => {
              onSelect(node as GraphNode)
            }}
            nodeCanvasObject={(node: object, ctx: CanvasRenderingContext2D, scale: number) => {
              const typed = node as PositionedNode
              const x = typed.x ?? 0
              const y = typed.y ?? 0

              // Size carries inbound links, so a well-connected page reads as
              // substantial and an orphan as a small isolated dot.
              const radius = 3 + Math.min(typed.inbound, 8) * 0.7
              const isSelected = typed.id === selectedId

              ctx.beginPath()
              ctx.arc(x, y, radius, 0, 2 * Math.PI)
              ctx.fillStyle = colorFor(typed)
              ctx.fill()

              // Orphans get a ring as well as a colour: the one distinction the
              // graph exists to show must not depend on hue alone.
              if (typed.classification === 'orphan') {
                ctx.strokeStyle = palette.orphan
                ctx.lineWidth = 1 / scale
                ctx.beginPath()
                ctx.arc(x, y, radius + 3, 0, 2 * Math.PI)
                ctx.stroke()
              }

              if (isSelected) {
                ctx.strokeStyle = palette.ink
                ctx.lineWidth = 2 / scale
                ctx.beginPath()
                ctx.arc(x, y, radius + 5, 0, 2 * Math.PI)
                ctx.stroke()
              }

              // Labels only once zoomed in, or a few hundred pages become an
              // unreadable wall of overlapping text.
              if (scale > 1.6) {
                const label = typed.title.slice(0, 40)
                ctx.font = `${11 / scale}px "IBM Plex Sans", sans-serif`
                ctx.fillStyle = palette.ink
                ctx.textAlign = 'center'
                ctx.fillText(label, x, y + radius + 9 / scale)
              }
            }}
            nodePointerAreaPaint={(node: object, color: string, ctx: CanvasRenderingContext2D) => {
              const typed = node as PositionedNode
              ctx.fillStyle = color
              ctx.beginPath()
              ctx.arc(typed.x ?? 0, typed.y ?? 0, 8, 0, 2 * Math.PI)
              ctx.fill()
            }}
          />
        )}
      </Suspense>
    </div>
  )
}
