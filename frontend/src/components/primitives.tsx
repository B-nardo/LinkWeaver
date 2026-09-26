import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react'
import { useId } from 'react'

import { ApiError } from '@/lib/api'

/**
 * The shared vocabulary of the interface.
 *
 * Kept deliberately small and unfashionable: three radii used for different
 * classes of object, one focus treatment, and error states that distinguish a
 * cold-starting server from a real failure. Nothing here is a generic "Card".
 */

export function Button({
  variant = 'primary',
  className = '',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'quiet' | 'danger' }) {
  const styles = {
    primary: 'bg-opportunity text-canvas hover:bg-opportunity-hover',
    quiet: 'border border-line text-ink hover:border-line-strong hover:bg-sunken',
    danger: 'border border-line text-orphan hover:border-orphan hover:bg-orphan-wash',
  }[variant]

  return (
    <button
      {...props}
      className={`rounded-hair px-3.5 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-55 ${styles} ${className}`}
    />
  )
}

export function Field({
  label,
  hint,
  error,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & {
  label: string
  hint?: string
  error?: string
}) {
  const id = useId()
  const describedBy =
    error !== undefined ? `${id}-error` : hint !== undefined ? `${id}-hint` : undefined

  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-ink block text-sm font-medium">
        {label}
      </label>
      <input
        {...props}
        id={id}
        aria-invalid={error !== undefined}
        aria-describedby={describedBy}
        className={`rounded-hair bg-surface text-ink placeholder:text-ink-faint w-full border px-3 py-2 text-sm ${
          error !== undefined ? 'border-orphan' : 'border-line'
        }`}
      />
      {hint !== undefined && error === undefined && (
        <p id={`${id}-hint`} className="text-ink-faint text-xs">
          {hint}
        </p>
      )}
      {error !== undefined && (
        <p id={`${id}-error`} className="text-orphan text-xs">
          {error}
        </p>
      )}
    </div>
  )
}

export function Skeleton({ className = '' }: { className?: string }) {
  return <div aria-hidden="true" className={`rounded-hair bg-sunken animate-pulse ${className}`} />
}

/**
 * A free-tier backend sleeps when idle, so a slow first request is expected
 * rather than broken. Saying so keeps a normal cold start from reading as a
 * failure.
 */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const isColdStart = error instanceof ApiError && error.kind === 'timeout'
  const message = isColdStart
    ? 'The server is waking up. Free-tier machines sleep when idle, so the first request can take a few seconds.'
    : error instanceof ApiError
      ? error.message
      : 'Something went wrong.'

  return (
    <div role="alert" className="rounded-panel border-line bg-surface border p-6">
      <p className="font-display text-ink text-lg">
        {isColdStart ? 'Waking the server' : 'That did not work'}
      </p>
      <p className="text-ink-soft mt-1.5 max-w-prose text-sm">{message}</p>
      {onRetry !== undefined && (
        <Button variant="quiet" onClick={onRetry} className="mt-4">
          Try again
        </Button>
      )}
    </div>
  )
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <div className="border-line rounded-panel border border-dashed px-6 py-14 text-center">
      <p className="font-display text-ink text-lg">{title}</p>
      <p className="text-ink-soft mx-auto mt-1.5 max-w-sm text-sm">{description}</p>
      {action !== undefined && <div className="mt-5">{action}</div>}
    </div>
  )
}

/**
 * Status is carried by a word plus a shape, never by colour alone — colour is a
 * reinforcement for sighted users, not the only channel.
 */
export function StatusBadge({ status, label }: { status: string; label: string }) {
  const tone =
    status === 'failed'
      ? 'text-orphan border-orphan/40 bg-orphan-wash'
      : status === 'done'
        ? 'text-opportunity border-opportunity/40 bg-opportunity-wash'
        : 'text-ink-soft border-line bg-sunken'

  return (
    <span
      className={`rounded-pill inline-flex items-center gap-1.5 border px-2.5 py-0.5 font-mono text-xs ${tone}`}
    >
      {status !== 'done' && status !== 'failed' && (
        <span className="rounded-pill size-1.5 animate-pulse bg-current" aria-hidden="true" />
      )}
      {label}
    </span>
  )
}
