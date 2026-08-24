import { expect, test } from '@playwright/test'

/**
 * The sign-in flow, in a real browser, through the Vite proxy.
 *
 * This is the only test in the suite that exercises the property the whole cookie design rests
 * on: that the SPA and the API share an origin, so `__Host-` cookies are accepted, sent back,
 * and invisible to script. Nothing below the browser can check that — a functional test can
 * assert the Set-Cookie header, but only a browser decides whether to honour it.
 */

// The SPA, not the API. Cookies are only same-origin when the app is reached through the
// proxy, which is the whole point.
const SPA = process.env.E2E_SPA_URL ?? 'http://localhost:5173'

test.describe('sign in', () => {
  test('a fixture user can sign in, see their account, and sign out', async ({ page }) => {
    await page.goto(`${SPA}/sign-in`)

    await page.getByLabel('Benutzername').fill('admin')
    await page.getByLabel('Passwort').fill('demo-password-not-for-real-use')
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await expect(page).toHaveURL(/\/account$/)
    await expect(page.getByText('admin').first()).toBeVisible()

    // The browser accepted the __Host- cookies and is sending them back.
    const cookies = await page.context().cookies()
    const access = cookies.find((cookie) => cookie.name === '__Host-bentley_at')

    expect(access, 'the browser should have stored the access cookie').toBeTruthy()
    expect(access!.httpOnly).toBe(true)

    // HttpOnly means script cannot read it. If this ever returns the token, the cookie flag
    // has been lost and an XSS becomes a session theft.
    const readable = await page.evaluate(() => document.cookie)
    expect(readable).not.toContain('__Host-bentley_at')

    // Sign out now lives in the account menu in the shell's top bar, so the menu has to be
    // opened first. exact: true — the account page also has an "Überall abmelden" button, and
    // "Abmelden" is a prefix of it.
    await page.getByRole('button', { name: 'Kontomenü' }).click()
    await page.getByRole('menuitem', { name: 'Abmelden', exact: true }).click()

    // Signed out lands on the sign-in page, which is the one place a signed-out visitor can be.
    await expect(page).toHaveURL(/\/sign-in$/)
    await expect(page.getByRole('heading', { name: 'Anmelden' })).toBeVisible()

    const afterSignOut = await page.context().cookies()
    const stillThere = afterSignOut.find(
      (cookie) => cookie.name === '__Host-bentley_at' && cookie.value !== '',
    )
    expect(stillThere, 'signing out must clear the access cookie').toBeFalsy()
  })

  test('an unauthenticated visitor is redirected away from the account page', async ({ page }) => {
    await page.goto(`${SPA}/account`)

    // The guard is navigation convenience, not authorization — but it should still work.
    await expect(page).toHaveURL(/\/sign-in\?redirect=/)
  })

  test('wrong credentials show the error without revealing whether the account exists', async ({ page }) => {
    await page.goto(`${SPA}/sign-in`)

    await page.getByLabel('Benutzername').fill('admin')
    await page.getByLabel('Passwort').fill('definitely-not-the-password')
    await page.getByRole('button', { name: 'Anmelden' }).click()

    // Deliberately still an English fragment: the SPA is German, but this text is the API's
    // problem+json `detail`, which is passed through untranslated by design.
    await expect(page.getByRole('alert')).toContainText('incorrect')
  })
})
