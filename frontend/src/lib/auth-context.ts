import { createContext, useContext } from 'react'

import type { AuthUser } from '@/types'

export const TOKEN_STORAGE_KEY = 'lw-token'

export interface AuthContextValue {
  user: AuthUser | null
  /** True until the stored token has been checked against the API. */
  isResolving: boolean
  signIn: (token: string, user: AuthUser) => void
  signOut: () => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider.')
  }

  return context
}
