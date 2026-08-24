import { ApiError, parseProblem } from './problem'

/**
 * The only place in the SPA that talks to the API.
 *
 * Four things it does that a bare `fetch` does not, each of which breaks something if it is
 * skipped:
 *
 *  1. **Sends cookies** (`credentials: 'same-origin'`). The tokens are HttpOnly cookies
 *     (ADR-0002); without this every request is anonymous.
 *  2. **Echoes the CSRF token.** Cookie auth means the browser attaches credentials to
 *     cross-site requests automatically; the double-submit header is what proves the request
 *     came from our own page.
 *  3. **Turns problem+json into a typed ApiError**, so callers branch on a status rather than
 *     matching message strings.
 *  4. **Single-flight refresh on 401**, then replays the original request.
 *
 * Point 4 is the one that matters most and is easiest to get wrong. Refresh tokens ROTATE, and
 * presenting an already-rotated one is treated as theft: it revokes the whole family and logs
 * the user out everywhere. A page that fires six requests on load and refreshes six times would
 * do exactly that to itself. So concurrent 401s share ONE refresh and all wait for it.
 */

const CSRF_COOKIE = '__Host-bentley_csrf'
const CSRF_HEADER = 'X-CSRF-Token'
const REFRESH_PATH = '/api/v1/auth/refresh'

export interface RequestOptions extends Omit<RequestInit, 'body'> {
  body?: unknown
  /** Internal: prevents a replayed request from trying to refresh a second time. */
  _isRetry?: boolean
}

type Unauthenticated = () => void
type LoadingEvent = () => void

/** The in-flight refresh, shared by every request that hits a 401 while it is running. */
let refreshInFlight: Promise<boolean> | null = null

let onUnauthenticated: Unauthenticated = () => {}

/** Called when refreshing fails — the session is genuinely over. */
export function setUnauthenticatedHandler(handler: Unauthenticated): void {
  onUnauthenticated = handler
}

// Bracketing every request so the global loading bar can react. Registered from outside the
// client (in main.ts) rather than imported here, mirroring `setUnauthenticatedHandler` and
// keeping this module free of a Pinia dependency — which would be circular, since the stores
// import the client to make requests.
let onLoadingStart: LoadingEvent = () => {}
let onLoadingEnd: LoadingEvent = () => {}

/** Drives the global loading bar: `start` fires when a request begins, `end` when it settles. */
export function setLoadingHandler(start: LoadingEvent, end: LoadingEvent): void {
  onLoadingStart = start
  onLoadingEnd = end
}

/** Exported for tests; there is no other reason to reset module state. */
export function resetClientState(): void {
  refreshInFlight = null
  onLoadingStart = () => {}
  onLoadingEnd = () => {}
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : null
}

function buildHeaders(options: RequestOptions): Headers {
  const headers = new Headers(options.headers)

  if (options.body !== undefined && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const csrf = readCookie(CSRF_COOKIE)
  if (csrf !== null) {
    headers.set(CSRF_HEADER, csrf)
  }

  return headers
}

/**
 * Refresh once, however many callers are waiting.
 *
 * The promise is stored before it is awaited, so a second caller arriving mid-flight joins it
 * rather than starting a rotation of its own.
 */
async function refreshOnce(): Promise<boolean> {
  refreshInFlight ??= (async () => {
    try {
      const response = await fetch(REFRESH_PATH, {
        method: 'POST',
        credentials: 'same-origin',
        headers: buildHeaders({}),
      })
      return response.ok
    } catch {
      return false
    } finally {
      // Cleared in `finally` so a failed refresh does not wedge every later request on a
      // permanently rejected promise.
      refreshInFlight = null
    }
  })()

  return refreshInFlight
}

export async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
  onLoadingStart()
  try {
    const response = await fetch(path, {
      ...options,
      method,
      credentials: 'same-origin',
      headers: buildHeaders(options),
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    })

    if (response.status === 401 && !options._isRetry && path !== REFRESH_PATH) {
      const refreshed = await refreshOnce()

      if (refreshed) {
        return request<T>(method, path, { ...options, _isRetry: true })
      }

      // Refresh failed: the session is over. Tell the app once, then surface the error.
      onUnauthenticated()
    }

    if (!response.ok) {
      throw new ApiError(await parseProblem(response), response)
    }

    if (response.status === 204) {
      return undefined as T
    }

    return (await response.json()) as T
  } finally {
    // Always settle, including the throw paths above — a counter that only goes up on error
    // would leave the loading bar stuck on for the rest of the session.
    onLoadingEnd()
  }
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, options),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>('POST', path, { ...options, body }),
  patch: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>('PATCH', path, { ...options, body }),
  // PUT, for endpoints that replace a whole collection — a role's permission set, a group's
  // members. Sending a delta instead would race with itself the moment two administrators
  // have the same screen open.
  put: <T>(path: string, body?: unknown, options?: RequestOptions) =>
    request<T>('PUT', path, { ...options, body }),
  delete: <T>(path: string, options?: RequestOptions) => request<T>('DELETE', path, options),
}
