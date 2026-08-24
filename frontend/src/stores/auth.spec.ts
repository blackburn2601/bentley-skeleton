import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createPinia, setActivePinia } from 'pinia'

import * as authApi from '@/api/auth'
import type { Me } from '@/api/auth'

import { useAuthStore } from './auth'

/**
 * The MFA-pending half-session (ADR-0026).
 *
 * The store is the single source of truth for whether a caller owes a second factor. While they
 * do, `isAuthenticated` is deliberately false and `/me` is never called — the pending access
 * token carries `roles: []` and the MfaStageVoter denies it everything but the verify endpoints,
 * so loading would 403. These tests pin that the branch survives and that the three ways out
 * (TOTP, recovery, sign-out) all clear the flag, while a lost session on a wrong code does not.
 */
vi.mock('@/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  logoutEverywhere: vi.fn(),
  me: vi.fn(),
  verifyMfa: vi.fn(),
  verifyMfaRecovery: vi.fn(),
}))

function meFixture(enrolled = false): Me {
  return {
    id: 'a-user',
    username: 'someone',
    roles: [],
    permissions: [],
    mfaEnrolled: enrolled,
    mfaRequired: false,
  }
}

function loginResponse(mfaRequired: authApi.MfaRequired): authApi.LoginResponse {
  return { id: 'a-user', username: 'someone', roles: [], mfaRequired }
}

describe('useAuthStore — MFA pending state', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('holds the caller at pending when a second factor is owed, without loading /me', async () => {
    vi.mocked(authApi.login).mockResolvedValue(loginResponse('pending'))

    const auth = useAuthStore()
    await auth.signIn('someone', 'pw')

    expect(auth.mfaPending).toBe(true)
    expect(auth.isAuthenticated).toBe(false)
    expect(authApi.me).not.toHaveBeenCalled()
  })

  it('completes a floor sign-in by loading /me', async () => {
    vi.mocked(authApi.login).mockResolvedValue(loginResponse(false))
    vi.mocked(authApi.me).mockResolvedValue(meFixture())

    const auth = useAuthStore()
    await auth.signIn('someone', 'pw')

    expect(auth.mfaPending).toBe(false)
    expect(auth.isAuthenticated).toBe(true)
    expect(authApi.me).toHaveBeenCalledOnce()
  })

  it('clears pending and loads the session once a TOTP is proven', async () => {
    vi.mocked(authApi.verifyMfa).mockResolvedValue(loginResponse('verified'))
    vi.mocked(authApi.me).mockResolvedValue(meFixture(true))

    const auth = useAuthStore()
    auth.mfaPending = true

    await auth.verifyMfa('123456')

    expect(auth.mfaPending).toBe(false)
    expect(auth.isAuthenticated).toBe(true)
    expect(authApi.verifyMfa).toHaveBeenCalledWith('123456')
    expect(authApi.me).toHaveBeenCalledOnce()
  })

  it('clears pending and loads the session once a recovery code is spent', async () => {
    vi.mocked(authApi.verifyMfaRecovery).mockResolvedValue(loginResponse('verified'))
    vi.mocked(authApi.me).mockResolvedValue(meFixture(true))

    const auth = useAuthStore()
    auth.mfaPending = true

    await auth.verifyMfaRecovery('RECOVERY-1')

    expect(auth.mfaPending).toBe(false)
    expect(auth.isAuthenticated).toBe(true)
    expect(authApi.verifyMfaRecovery).toHaveBeenCalledWith('RECOVERY-1')
  })

  it('clears pending on sign-out even when the server is unreachable', async () => {
    // A client that cannot reach the server must still stop presenting itself as signed in —
    // and must not keep a caller stuck on the MFA screen with no way out.
    vi.mocked(authApi.logout).mockRejectedValue(new Error('network'))

    const auth = useAuthStore()
    auth.mfaPending = true
    auth.user = meFixture()

    // signOut uses try/finally without a catch: the rejection still propagates after the state
    // is cleared, so the caller must absorb it. The point under test is the clearing, not the
    // propagation.
    await expect(auth.signOut()).rejects.toThrow('network')

    expect(auth.mfaPending).toBe(false)
    expect(auth.user).toBeNull()
  })

  it('onSessionLost keeps the pending flag, so a wrong code does not strand the caller off the MFA screen', () => {
    // The API client calls onSessionLost on every verify 401 — a pending caller's refresh always
    // fails, since no refresh cookie was issued. Clearing mfaPending here would drop them back
    // to the sign-in screen and discard the half-checked password; leaving it keeps them where
    // a retry is cheap.
    const auth = useAuthStore()
    auth.mfaPending = true
    auth.user = meFixture()

    auth.onSessionLost()

    expect(auth.user).toBeNull()
    expect(auth.mfaPending).toBe(true)
  })
})