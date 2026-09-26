/**
 * Thin typed wrapper over `fetch` for the Linkweaver API.
 *
 * Two concerns shape this file beyond plain request/response handling:
 *
 * 1. The API is deployed separately from the SPA and, on free-tier hosting,
 *    sleeps when idle. A first request can therefore hang for many seconds
 *    rather than fail outright, so requests carry an abort deadline and report
 *    a distinct `timeout` kind. The UI uses that to show "waking the server"
 *    instead of a generic error.
 * 2. Auth is Sanctum bearer tokens (the SPA is cross-origin, so cookie-based
 *    session auth is not used). The token is held in module scope and injected
 *    here so no caller has to remember to attach it.
 */

const BASE_URL = (import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api').replace(/\/+$/, '')

/** Requests that exceed this are assumed to be hitting a cold backend. */
const REQUEST_TIMEOUT_MS = 20_000

export type ApiErrorKind = 'http' | 'network' | 'timeout' | 'parse'

export class ApiError extends Error {
  readonly kind: ApiErrorKind
  readonly status: number | null
  readonly body: unknown

  constructor(
    message: string,
    kind: ApiErrorKind,
    status: number | null = null,
    body: unknown = null,
  ) {
    super(message)
    this.name = 'ApiError'
    this.kind = kind
    this.status = status
    this.body = body
  }

  /** Validation failures carry a Laravel-shaped `errors` bag. */
  get validationErrors(): Record<string, string[]> | null {
    if (this.status !== 422 || typeof this.body !== 'object' || this.body === null) {
      return null
    }

    const errors = (this.body as { errors?: unknown }).errors

    return typeof errors === 'object' && errors !== null
      ? (errors as Record<string, string[]>)
      : null
  }
}

let authToken: string | null = null

export function setAuthToken(token: string | null): void {
  authToken = token
}

export function getAuthToken(): string | null {
  return authToken
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')

  if (init.body !== undefined && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  if (authToken !== null) {
    headers.set('Authorization', `Bearer ${authToken}`)
  }

  let response: Response

  try {
    response = await fetch(`${BASE_URL}${path}`, {
      ...init,
      headers,
      signal: init.signal ?? controller.signal,
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw new ApiError('The server took too long to respond.', 'timeout')
    }

    throw new ApiError('Could not reach the server.', 'network')
  } finally {
    clearTimeout(timer)
  }

  if (response.status === 204) {
    return undefined as T
  }

  const raw = await response.text()
  let parsed: unknown = null

  if (raw !== '') {
    try {
      parsed = JSON.parse(raw)
    } catch {
      if (response.ok) {
        throw new ApiError('The server returned a malformed response.', 'parse', response.status)
      }
    }
  }

  if (!response.ok) {
    const message =
      (typeof parsed === 'object' && parsed !== null
        ? (parsed as { message?: string }).message
        : null) ?? `Request failed with status ${response.status}.`

    throw new ApiError(message, 'http', response.status, parsed)
  }

  return parsed as T
}
