import { expect, test } from '@playwright/test'

/**
 * The stack is genuinely up, and the security posture survives a real HTTP round trip.
 *
 * Deliberately API-level rather than UI-level: it asserts the properties that no unit or
 * functional test can — that the built image serves them, through a real network hop, with
 * the production configuration.
 */
test.describe('API smoke', () => {
  test('readiness reports every dependency', async ({ request }) => {
    const response = await request.get('/health/ready')

    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body.status).toBe('ready')
    expect(Object.keys(body.checks)).toEqual(
      expect.arrayContaining(['database', 'cache', 'migrations']),
    )
  })

  test('security headers are present on a real response', async ({ request }) => {
    const response = await request.get('/health/live')
    const headers = response.headers()

    expect(headers['x-content-type-options']).toBe('nosniff')
    expect(headers['x-frame-options']).toBe('DENY')
    expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin')
    // Every response is traceable back to a log line and an audit row.
    expect(headers['x-request-id']).toBeTruthy()
  })

  test('an unauthenticated request is refused in problem+json', async ({ request }) => {
    const response = await request.get('/api/v1/auth/me')

    expect(response.status()).toBe(401)
    expect(response.headers()['content-type']).toContain('application/problem+json')

    const body = await response.json()
    expect(body.status).toBe(401)
    expect(body.type).toContain('rfc9457')
  })

  test('login sets HttpOnly cookies and never returns a token in the body', async ({ request }) => {
    const response = await request.post('/api/v1/auth/login', {
      data: { username: 'admin', password: 'demo-password-not-for-real-use' },
    })

    expect(response.status()).toBe(200)

    const body = await response.text()
    expect(body).not.toContain('eyJ') // no JWT in the body — that is what the cookie is for

    const setCookie = response.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie')
    const access = setCookie.find((h) => h.value.startsWith('__Host-bentley_at='))

    expect(access, 'the access cookie should be set').toBeTruthy()
    expect(access!.value.toLowerCase()).toContain('httponly')
    expect(access!.value.toLowerCase()).toContain('samesite=strict')
  })

  test('repeated bad logins are rate limited with Retry-After', async ({ request }) => {
    const attempt = () =>
      request.post('/api/v1/auth/login', {
        data: { username: 'e2e-ratelimit', password: 'definitely-wrong-password' },
      })

    let limited: Awaited<ReturnType<typeof attempt>> | null = null

    for (let i = 0; i < 8; i++) {
      const response = await attempt()
      if (response.status() === 429) {
        limited = response
        break
      }
    }

    expect(limited, 'repeated failures should eventually be rate limited').not.toBeNull()
    expect(limited!.headers()['retry-after']).toBeTruthy()
  })
})
