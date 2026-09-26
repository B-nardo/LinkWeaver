import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { apiFetch } from './api'
import type { Project, ProjectStatusPayload, Wrapped } from '@/types'

/** How often a running project is re-polled. */
const POLL_INTERVAL_MS = 2000

export function useProjects() {
  return useQuery({
    queryKey: ['projects'],
    queryFn: () => apiFetch<{ data: Project[] }>('/projects'),
    select: (response) => response.data,
  })
}

export function useProject(id: string | undefined) {
  return useQuery({
    queryKey: ['project', id],
    queryFn: () => apiFetch<Wrapped<Project>>(`/projects/${id ?? ''}`),
    select: (response) => response.data,
    enabled: id !== undefined,
  })
}

/**
 * Polls the lightweight status endpoint while the pipeline is running and stops
 * the moment it reaches a terminal state, so a finished project costs nothing.
 */
export function useProjectStatus(id: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['project-status', id],
    queryFn: () => apiFetch<ProjectStatusPayload>(`/projects/${id ?? ''}/status`),
    enabled: id !== undefined && enabled,
    refetchInterval: (query) => (query.state.data?.is_terminal === true ? false : POLL_INTERVAL_MS),
    staleTime: 0,
  })
}

export function useCreateProject() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { name: string; sitemap_url: string; skip_taxonomies: boolean }) =>
      apiFetch<Wrapped<Project>>('/projects', {
        method: 'POST',
        body: JSON.stringify(input),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}

export function useDeleteProject() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => apiFetch<void>(`/projects/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}
