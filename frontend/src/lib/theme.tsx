import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'

import { ThemeContext, THEME_STORAGE_KEY } from './theme-context'
import type { Theme } from './theme-context'

/**
 * Keeps theme state in sync with the `data-theme` attribute that the inline
 * script in index.html sets before first paint. That script is the source of
 * truth for the initial value; this provider only mirrors and mutates it, which
 * is why initial state is read from the DOM rather than recomputed here.
 */

function readInitialTheme(): Theme {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<Theme>(readInitialTheme)

  useEffect(() => {
    document.documentElement.dataset.theme = theme

    try {
      localStorage.setItem(THEME_STORAGE_KEY, theme)
    } catch {
      // Private browsing or a full quota: the theme still applies for this
      // session, it just will not be remembered. Not worth surfacing.
    }
  }, [theme])

  const toggleTheme = useCallback(() => {
    setTheme((current) => (current === 'dark' ? 'light' : 'dark'))
  }, [])

  return <ThemeContext.Provider value={{ theme, toggleTheme }}>{children}</ThemeContext.Provider>
}
