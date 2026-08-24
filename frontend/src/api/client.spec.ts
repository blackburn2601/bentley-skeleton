import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { api, request, resetClientState, setLoadingHandler, setUnauthenticatedHandler } from './client'
import { ApiError } from './problem'

/**
 * The fetch wrapper's behaviour — above all the single-flight refresh.
 *
 * That one is not a nicety. Refresh tokens rotate, and presenting an already-rotated one is
 * treated as theft: the server revokes the entire family and signs the user out everywhere. A
 * page that fires six requests on load and refreshes six times does that to itself, and the
 * resulting bug report is "it logs me out at random" — nearly impossible to diagnose from the
 * outside. So the concurrency assertion below is the most valuable test in this file.
 */

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': status === 204 ? 'text/plain' : 'application/json', ...headers },
  })
}

function problemResponse(status: number, extra: Record<string, unknown> = {}): Response {
  return new Response(
    JSON.stringify({
      type: 'https://datatracker.ietf.org/doc/html/rfc9457',
      title: 'Error',
      status,
      detail: 'Something went wrong.',
      ...extra,
    }),
    { status, headers: { 'content-type': 'application/problem+json' } },
  )
}

describe('api client', () => {
  beforeEach(() => {
    resetClientState()
    setUnauthenticatedHandler(() => {})
    document.cookie = ''
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('sends cookies, because the tokens are HttpOnly cookies', async () => {
    vi.mocked(fetch).mockResolvedValue(jsonResponse(200, { ok: true }))

    await api.get('/api/v1/thing')

    expect(fetch).toHaveBeenCalledWith('/api/v1/thing', expect.objectContaining({ credentials: 'same-origin' }))
  })

  it('echoes the CSRF cookie in the header', async () => {
    // The __Host- prefix requires Secure and Path=/; a DOM implementation that enforces the
    // prefix rules will silently drop the cookie otherwise — which is the same reason the
    // real cookies are set that way (ADR-0002).
    document.cookie = '__Host-bentley_csrf=csrf-value-123; path=/; secure'
    vi.mocked(fetch).mockResolvedValue(jsonResponse(200, {}))

    await api.post('/api/v1/thing', { a: 1 })

    const init = vi.mocked(fetch).mock.calls[0][1] as RequestInit
    expect(new Headers(init.headers).get('X-CSRF-Token')).toBe('csrf-value-123')
  })

  it('turns problem+json into a typed error with field errors', async () => {
    vi.mocked(fetch).mockImplementation(async () =>
      problemResponse(422, {
        detail: 'The request body did not pass validation.',
        requestId: 'req-abc',
        errors: [
          { field: 'username', message: 'This value is not a valid username.' },
          { field: 'username', message: 'A second complaint about the same field.' },
          { field: 'password', message: 'This value is too short.' },
        ],
      }),
    )

    // ONE call. A Response body can only be read once, so asserting twice against the same
    // mocked Response would hand the second attempt an already-consumed stream and quietly
    // produce the fallback problem — a test that passes for the wrong reason.
    const error = await api.post('/api/v1/auth/login', {}).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)

    const apiError = error as ApiError
    expect(apiError.status).toBe(422)
    expect(apiError.requestId).toBe('req-abc')
    expect(apiError.fieldErrors).toEqual({
      username: 'This value is not a valid username.',
      password: 'This value is too short.',
    })
  })

  it('produces a typed error even when the server returns HTML', async () => {
    vi.mocked(fetch).mockResolvedValue(
      new Response('<html>502 Bad Gateway</html>', { status: 502, headers: { 'content-type': 'text/html' } }),
    )

    await expect(api.get('/api/v1/thing')).rejects.toMatchObject({ status: 502 })
  })

  it('returns undefined for 204 rather than trying to parse a body', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(null, { status: 204 }))

    await expect(api.post('/api/v1/auth/logout')).resolves.toBeUndefined()
  })

  it('refreshes once on 401 and replays the original request', async () => {
    vi.mocked(fetch)
      .mockResolvedValueOnce(problemResponse(401))
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // the refresh
      .mockResolvedValueOnce(jsonResponse(200, { ok: true })) // the replay

    await expect(api.get('/api/v1/thing')).resolves.toEqual({ ok: true })

    const paths = vi.mocked(fetch).mock.calls.map((call) => call[0])
    expect(paths).toEqual(['/api/v1/thing', '/api/v1/auth/refresh', '/api/v1/thing'])
  })

  it('refreshes ONLY ONCE for concurrent 401s', async () => {
    // The property that stops the SPA from revoking its own session. Six requests hit 401
    // together; six refreshes would present six tokens, five of them already rotated, and the
    // server would treat that as theft and log the user out everywhere.
    let refreshCalls = 0

    vi.mocked(fetch).mockImplementation(async (input) => {
      const url = String(input)

      if (url === '/api/v1/auth/refresh') {
        refreshCalls++
        await new Promise((resolve) => setTimeout(resolve, 10))
        return new Response(null, { status: 204 })
      }

      // Unauthorised until the refresh has completed.
      return refreshCalls > 0 ? jsonResponse(200, { ok: true }) : problemResponse(401)
    })

    const results = await Promise.all([
      api.get('/api/v1/a'),
      api.get('/api/v1/b'),
      api.get('/api/v1/c'),
      api.get('/api/v1/d'),
      api.get('/api/v1/e'),
      api.get('/api/v1/f'),
    ])

    expect(refreshCalls).toBe(1)
    expect(results).toHaveLength(6)
  })

  it('does not try to refresh a failed refresh', async () => {
    vi.mocked(fetch).mockResolvedValue(problemResponse(401))

    await expect(request('POST', '/api/v1/auth/refresh')).rejects.toThrow(ApiError)

    expect(vi.mocked(fetch)).toHaveBeenCalledTimes(1)
  })

  it('notifies the app when refreshing fails', async () => {
    const onUnauthenticated = vi.fn()
    setUnauthenticatedHandler(onUnauthenticated)

    vi.mocked(fetch)
      .mockResolvedValueOnce(problemResponse(401))
      .mockResolvedValueOnce(problemResponse(401)) // the refresh also fails

    await expect(api.get('/api/v1/thing')).rejects.toThrow(ApiError)

    expect(onUnauthenticated).toHaveBeenCalledOnce()
  })

  it('gives up after one replay rather than looping', async () => {
    vi.mocked(fetch)
      .mockResolvedValueOnce(problemResponse(401))
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // refresh succeeds
      .mockResolvedValueOnce(problemResponse(401)) // but the replay is still 401

    await expect(api.get('/api/v1/thing')).rejects.toThrow(ApiError)

    // Original, refresh, replay — and then it stops. An endpoint the user genuinely cannot
    // reach must not become an infinite refresh loop.
    expect(vi.mocked(fetch)).toHaveBeenCalledTimes(3)
  })

  it('sends a PUT with its body, the CSRF header and cookies', async () => {
    document.cookie = '__Host-bentley_csrf=put-token; path=/; secure'
    const fetchMock = vi.fn().mockResolvedValue(new Response('{"ok":true}', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    await api.put('/api/v1/admin/roles/abc/permissions', { permissions: ['user.read'] })

    const [path, init] = fetchMock.mock.calls[0]
    expect(path).toBe('/api/v1/admin/roles/abc/permissions')
    expect(init.method).toBe('PUT')
    expect(init.credentials).toBe('same-origin')
    expect(init.headers.get('X-CSRF-Token')).toBe('put-token')
    expect(JSON.parse(init.body)).toEqual({ permissions: ['user.read'] })
  })

  describe('loading handler', () => {
    it('fires start then end for a successful request', async () => {
      const start = vi.fn()
      const end = vi.fn()
      setLoadingHandler(start, end)

      vi.mocked(fetch).mockResolvedValue(jsonResponse(200, { ok: true }))

      await api.get('/api/v1/thing')

      expect(start).toHaveBeenCalledOnce()
      expect(end).toHaveBeenCalledOnce()
      expect(end).toHaveBeenCalledAfter(start)
    })

    it('fires end even when the request throws', async () => {
      // A counter that only increments on error would leave the loading bar stuck on for the
      // rest of the session.
      const start = vi.fn()
      const end = vi.fn()
      setLoadingHandler(start, end)

      vi.mocked(fetch).mockResolvedValue(problemResponse(500))

      await expect(api.get('/api/v1/thing')).rejects.toThrow(ApiError)

      expect(start).toHaveBeenCalledOnce()
      expect(end).toHaveBeenCalledOnce()
    })

    it('does not count the silent refresh, but counts the replayed request', async () => {
      // refreshOnce() uses raw `fetch`, not `request`, so it must not drive the bar; the retry
      // goes through `request` again and so is bracketed like any other request.
      const start = vi.fn()
      const end = vi.fn()
      setLoadingHandler(start, end)

      vi.mocked(fetch)
        .mockResolvedValueOnce(problemResponse(401))
        .mockResolvedValueOnce(new Response(null, { status: 204 })) // the refresh
        .mockResolvedValueOnce(jsonResponse(200, { ok: true })) // the replay

      await api.get('/api/v1/thing')

      // Two starts (original + replay) and two ends — the refresh contributes neither.
      expect(start).toHaveBeenCalledTimes(2)
      expect(end).toHaveBeenCalledTimes(2)
    })
  })
})
