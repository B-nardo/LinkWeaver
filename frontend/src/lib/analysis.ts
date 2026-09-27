import { keepPreviousData, useQuery } from '@tanstack/react-query'

import { apiFetch } from './api'
import type { GraphPayload, Paginated, PageQueryParams, ProjectPage } from '@/types'

function toQueryString(params: PageQueryParams): string {
  const search = new URLSearchParams()

  if (params.filter != null) search.set('filter', params.filter)
  if (params.sort !== undefined) search.set('sort', params.sort)
  if (params.direction !== undefined) search.set('direction', params.direction)
  if (params.page !== undefined && params.page > 1) search.set('page', String(params.page))

  const query = search.toString()

  return query === '' ? '' : `?${query}`
}

export function usePages(projectId: string | undefined, params: PageQueryParams) {
  return useQuery({
    queryKey: ['pages', projectId, params],
    queryFn: () =>
      apiFetch<Paginated<ProjectPage>>(
        `/projects/${projectId ?? ''}/pages${toQueryString(params)}`,
      ),
    enabled: projectId !== undefined,
    // Keeps the previous page on screen while the next one loads, so changing a
    // filter does not collapse the table to a skeleton and back.
    placeholderData: keepPreviousData,
  })
}

/**
 * The whole graph in one request. Projects are capped at a few hundred pages,
 * so paginating the structure would cost more in complexity than it saves —
 * and a partial graph is a misleading graph.
 */
export function useGraph(projectId: string | undefined, enabled = true) {
  return useQuery({
    queryKey: ['graph', projectId],
    queryFn: () => apiFetch<GraphPayload>(`/projects/${projectId ?? ''}/graph`),
    enabled: projectId !== undefined && enabled,
  })
}
