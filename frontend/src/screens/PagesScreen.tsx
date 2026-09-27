import { Link, useParams } from 'react-router-dom'

import { PagesTable } from '@/components/PagesTable'
import { Skeleton } from '@/components/primitives'
import { useProject } from '@/lib/projects'

/**
 * The pages table on its own route, so a particular view of a site is a URL
 * somebody can bookmark or send to a colleague.
 */
export function PagesScreen() {
  const { id } = useParams<{ id: string }>()
  const project = useProject(id)

  return (
    <div className="mx-auto max-w-5xl px-6 py-12">
      <Link to={`/projects/${id ?? ''}`} className="text-ink-soft hover:text-ink font-mono text-xs">
        ← Overview
      </Link>

      <div className="mt-4 mb-10">
        {project.isPending ? (
          <Skeleton className="h-9 w-64" />
        ) : (
          <>
            <h1 className="font-display text-ink text-3xl font-semibold">Pages</h1>
            <p className="text-ink-soft mt-1.5 text-sm">
              Every page in {project.data?.name ?? 'this audit'}, with the in-content links pointing
              at it. Sorted fewest links first, because those are the ones worth fixing.
            </p>
          </>
        )}
      </div>

      <PagesTable projectId={id} />
    </div>
  )
}
