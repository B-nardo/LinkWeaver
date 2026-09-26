import type { ReactNode } from 'react'
import { Link, Navigate, Route, Routes, useLocation } from 'react-router-dom'

import { Button, Skeleton } from '@/components/primitives'
import { useAuth } from '@/lib/auth-context'
import { useTheme } from '@/lib/theme-context'
import { AuthScreen } from '@/screens/AuthScreen'
import { ProjectScreen } from '@/screens/ProjectScreen'
import { ProjectsScreen } from '@/screens/ProjectsScreen'

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()

  return (
    <button
      type="button"
      onClick={toggleTheme}
      aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}
      className="rounded-hair border-line text-ink-soft hover:border-line-strong hover:text-ink border px-2.5 py-1 font-mono text-xs transition-colors"
    >
      {theme === 'dark' ? 'light' : 'dark'}
    </button>
  )
}

function Chrome({ children }: { children: ReactNode }) {
  const { user, signOut } = useAuth()

  return (
    <div className="bg-canvas min-h-dvh">
      <header className="border-line border-b">
        <div className="mx-auto flex max-w-4xl items-center justify-between px-6 py-5">
          <Link to="/projects" className="font-display text-ink text-xl font-semibold">
            Linkweaver
          </Link>
          <div className="flex items-center gap-3">
            {user !== null && (
              <span className="text-ink-faint hidden font-mono text-xs sm:inline">
                {user.email}
              </span>
            )}
            <ThemeToggle />
            {user !== null && (
              <Button variant="quiet" onClick={signOut} className="px-2.5 py-1 text-xs">
                Sign out
              </Button>
            )}
          </div>
        </div>
      </header>
      {children}
    </div>
  )
}

/**
 * Holds the route until the stored token has been checked. Rendering the login
 * screen first and then redirecting would flash a sign-in form at users who are
 * already signed in.
 */
function RequireAuth({ children }: { children: ReactNode }) {
  const { user, isResolving } = useAuth()
  const location = useLocation()

  if (isResolving) {
    return (
      <div className="mx-auto max-w-4xl space-y-6 px-6 py-12">
        <Skeleton className="h-9 w-56" />
        <Skeleton className="h-14 w-full" />
      </div>
    )
  }

  if (user === null) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <Chrome>{children}</Chrome>
}

function RedirectIfSignedIn({ children }: { children: ReactNode }) {
  const { user, isResolving } = useAuth()

  if (isResolving) return null
  if (user !== null) return <Navigate to="/projects" replace />

  return <>{children}</>
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/projects" replace />} />

      <Route
        path="/login"
        element={
          <RedirectIfSignedIn>
            <AuthScreen mode="login" />
          </RedirectIfSignedIn>
        }
      />
      <Route
        path="/register"
        element={
          <RedirectIfSignedIn>
            <AuthScreen mode="register" />
          </RedirectIfSignedIn>
        }
      />

      <Route
        path="/projects"
        element={
          <RequireAuth>
            <ProjectsScreen />
          </RequireAuth>
        }
      />
      <Route
        path="/projects/:id"
        element={
          <RequireAuth>
            <ProjectScreen />
          </RequireAuth>
        }
      />

      <Route
        path="*"
        element={
          <Chrome>
            <div className="mx-auto max-w-4xl px-6 py-24">
              <h1 className="font-display text-ink text-3xl">Nothing here</h1>
              <p className="text-ink-soft mt-2">That page does not exist.</p>
              <Link
                to="/projects"
                className="text-opportunity mt-5 inline-block underline underline-offset-4"
              >
                Back to audits
              </Link>
            </div>
          </Chrome>
        }
      />
    </Routes>
  )
}
