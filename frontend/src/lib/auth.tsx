import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'

import { apiFetch, setAuthToken } from './api'
import { AuthContext, TOKEN_STORAGE_KEY } from './auth-context'
import type { AuthUser } from '@/types'

/**
 * Holds the Sanctum bearer token and the signed-in user.
 *
 * The token is persisted so a refresh does not sign the user out, but it is
 * never trusted on face value: on boot it is sent to /auth/me, and a rejection
 * clears it. That keeps a revoked or expired token from leaving the UI in a
 * convincingly "signed in" state that fails on every request.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)

  // Derived at mount rather than defaulted to true and corrected in the effect:
  // with no stored token there is nothing to resolve, and setting state
  // synchronously inside an effect costs an extra render pass.
  const [isResolving, setIsResolving] = useState(
    () => localStorage.getItem(TOKEN_STORAGE_KEY) !== null,
  )
  const queryClient = useQueryClient()

  useEffect(() => {
    const stored = localStorage.getItem(TOKEN_STORAGE_KEY)

    if (stored === null) {
      return
    }

    setAuthToken(stored)

    let cancelled = false

    apiFetch<{ user: AuthUser }>('/auth/me')
      .then((response) => {
        if (!cancelled) setUser(response.user)
      })
      .catch(() => {
        if (cancelled) return
        setAuthToken(null)
        localStorage.removeItem(TOKEN_STORAGE_KEY)
      })
      .finally(() => {
        if (!cancelled) setIsResolving(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const signIn = useCallback((token: string, nextUser: AuthUser) => {
    setAuthToken(token)
    localStorage.setItem(TOKEN_STORAGE_KEY, token)
    setUser(nextUser)
  }, [])

  const signOut = useCallback(() => {
    // Best effort: the local session is cleared whether or not the API is
    // reachable, so signing out never leaves the user stuck.
    void apiFetch('/auth/logout', { method: 'POST' }).catch(() => undefined)

    setAuthToken(null)
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    setUser(null)
    queryClient.clear()
  }, [queryClient])

  return (
    <AuthContext.Provider value={{ user, isResolving, signIn, signOut }}>
      {children}
    </AuthContext.Provider>
  )
}
