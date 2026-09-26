import { createContext, useContext } from 'react'

/**
 * Theme context and consumer hook, kept separate from the provider component so
 * the provider module exports only components — a requirement for React Fast
 * Refresh to replace it without remounting the tree.
 */

export type Theme = 'light' | 'dark'

export const THEME_STORAGE_KEY = 'lw-theme'

export interface ThemeContextValue {
  theme: Theme
  toggleTheme: () => void
}

export const ThemeContext = createContext<ThemeContextValue | null>(null)

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext)

  if (context === null) {
    throw new Error('useTheme must be used inside a ThemeProvider.')
  }

  return context
}
