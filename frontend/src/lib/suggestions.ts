import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { apiFetch } from './api'
import type { Paginated, Suggestion, SuggestionQueryParams, Wrapped } from '@/types'

type Decision = 'approved' | 'rejected'

function toQueryString(params: SuggestionQueryParams): string {
  const search = new URLSearchParams()

  if (params.status != null) search.set('status', params.status)
  if (params.min_score != null) search.set('min_score', String(params.min_score))
  if (params.page !== undefined && params.page > 1) search.set('page', String(params.page))

  const query = search.toString()

  return query === '' ? '' : `?${query}`
}

export function useSuggestions(projectId: string | undefined, params: SuggestionQueryParams) {
  return useQuery({
    queryKey: ['suggestions', projectId, params],
    queryFn: () =>
      apiFetch<Paginated<Suggestion>>(
        `/projects/${projectId ?? ''}/suggestions${toQueryString(params)}`,
      ),
    enabled: projectId !== undefined,
    // Reviewing is a fast, repetitive activity; collapsing the table to a
    // skeleton between keystrokes would make it feel broken.
    placeholderData: keepPreviousData,
  })
}

/**
 * Approve or reject one suggestion.
 *
 * Updated optimistically: the reviewer is expected to move through the queue
 * with the A and R keys, and waiting for a round trip before the row responds
 * makes that rhythm impossible.
 */
export function useDecideSuggestion(projectId: string | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: Decision }) =>
      apiFetch<Wrapped<Suggestion>>(`/suggestions/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      }),
    onMutate: async ({ id, status }) => {
      await queryClient.cancelQueries({ queryKey: ['suggestions', projectId] })

      const snapshots = queryClient.getQueriesData<Paginated<Suggestion>>({
        queryKey: ['suggestions', projectId],
      })

      for (const [key, previous] of snapshots) {
        if (previous === undefined) continue

        queryClient.setQueryData<Paginated<Suggestion>>(key, {
          ...previous,
          data: previous.data.map((item) => (item.id === id ? { ...item, status } : item)),
        })
      }

      return { snapshots }
    },
    onError: (_error, _variables, context) => {
      // Put the queue back exactly as it was; a silently failed decision is
      // worse than an obvious one.
      for (const [key, previous] of context?.snapshots ?? []) {
        queryClient.setQueryData(key, previous)
      }
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ['suggestions', projectId] })
    },
  })
}

export function useBulkDecide(projectId: string | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ ids, status }: { ids: number[]; status: Decision }) =>
      apiFetch<{ updated: number }>(`/projects/${projectId ?? ''}/suggestions/bulk`, {
        method: 'POST',
        body: JSON.stringify({ ids, status }),
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['suggestions', projectId] })
    },
  })
}
