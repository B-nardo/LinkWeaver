import { useQuery } from '@tanstack/react-query'

import { apiFetch } from './api'
import type { Project, Wrapped } from '@/types'

/**
 * The public demo project.
 *
 * Asked for by name rather than by id, so the landing page does not need to
 * know which UUID the seeder happened to produce on this server.
 */
export function useDemoProject() {
  return useQuery({
    queryKey: ['demo'],
    queryFn: () => apiFetch<Wrapped<Project>>('/demo'),
    select: (response) => response.data,
    // The demo is fixture data; it does not change between page views.
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}
