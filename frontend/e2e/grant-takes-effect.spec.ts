import { expect, test, type APIRequestContext, type Page } from '@playwright/test'

/**
 * The test INV-13 names and this repository has never had.
 *
 * `docs/INVARIANTS.md` claims INV-13 is enforced by "the E2E test that grants a permission and
 * asserts access changes without logout". Until the admin API existed there was no way to grant
 * anything over HTTP, so the test could not be written.
 *
 * What it proves, end to end in a real browser: an access token is minted WITHOUT a permission,
 * an administrator grants it in a separate session, and the first session gains access on its
 * very next request — no re-authentication, no waiting out a cache TTL. That is the whole
 * argument of ADR-0011 (permissions resolved server-side, `perm_v` in the token rather than a
 * permission list) and the reason ADR-0021 exists to keep cache invalidation correct.
 *
 * If this fails with a 403 after the grant, `acl_version` was not bumped — which means
 * revocation is equally broken, and that is the security-relevant half.
 */
const SPA = process.env.E2E_SPA_URL ?? 'http://localhost:5173'
const API = process.env.E2E_BASE_URL ?? 'http://localhost:8080'
const PASSWORD = 'demo-password-not-for-real-use'

const USERS_ENDPOINT = '/api/v1/admin/users'
const GRANTED_ROLE = 'ROLE_AUDITOR'

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto(`${SPA}/sign-in`)
  await page.getByLabel('Username').fill(username)
  await page.getByLabel('Password').fill(PASSWORD)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/account$/)
}

/** The admin acts through the API directly: this test is about the viewer's session. */
async function signInAsAdmin(request: APIRequestContext): Promise<string> {
  const login = await request.post(`${API}/api/v1/auth/login`, {
    data: { username: 'admin', password: PASSWORD },
  })
  expect(login.ok(), await login.text()).toBe(true)

  const state = await request.storageState()
  return state.cookies.find((cookie) => cookie.name === '__Host-bentley_csrf')?.value ?? ''
}

test('a granted permission takes effect without signing in again', async ({ page, request }) => {
  // The admin session is opened first, so the viewer's role can be restored in the teardown
  // below whatever happens. The e2e database is shared and workers run one at a time, so a
  // test that leaves state behind is a test that passes exactly once.
  const csrf = await signInAsAdmin(request)
  const viewers = await request.get(`${API}${USERS_ENDPOINT}?q=viewer`)
  const viewerId = (await viewers.json()).items[0].id

  const revoke = () =>
    request.delete(`${API}${USERS_ENDPOINT}/${viewerId}/roles/${GRANTED_ROLE}`, {
      headers: { 'X-CSRF-Token': csrf },
    })

  // Start from a known state rather than assuming one.
  await revoke()

  try {
    // The viewer holds only an OBJECT-level grant on one user record — no class-level
    // user.read — so the admin area offers them nothing but the dashboard.
    await signIn(page, 'viewer')
    await expect(page.getByRole('link', { name: 'Users' })).toHaveCount(0)

    const before = await page.request.get(`${SPA}${USERS_ENDPOINT}`)
    expect(before.status(), 'the viewer must start without class-level user.read').toBe(403)

    // The administrator grants a role carrying user.read, in a completely separate session.
    const granted = await request.post(`${API}${USERS_ENDPOINT}/${viewerId}/roles`, {
      data: { role: GRANTED_ROLE },
      headers: { 'X-CSRF-Token': csrf },
    })
    expect(granted.status(), await granted.text()).toBe(201)

    // Back in the viewer's ORIGINAL session, on the same cookies and the same access token
    // that was minted before the grant existed. No second sign-in.
    const after = await page.request.get(`${SPA}${USERS_ENDPOINT}`)
    expect(after.status(), 'the grant must apply to the very next request').toBe(200)

    // And the navigation follows once /me is re-read — a reload, not a login.
    await page.reload()
    await expect(page.getByRole('link', { name: 'Users' })).toBeVisible()
  } finally {
    await revoke()
  }
})
