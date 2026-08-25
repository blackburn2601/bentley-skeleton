import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import * as authApi from '@/api/auth'
import type { Me } from '@/api/auth'
import { setUnauthenticatedHandler } from '@/api/client'

/**
 * Who is signed in, and what the UI may show them.
 *
 * The permission list here is **advisory** (INV-16). It exists so the UI can hide controls
 * that would fail, not to decide anything: the browser is not a trust boundary, and every
 * endpoint re-checks server-side. A hidden button is still a reachable endpoint, which is why
 * the IDOR suite calls endpoints directly and ignores the UI entirely.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<Me | null>(null)
  const loading = ref(false)
  /** False until the first /me has settled, so guards do not redirect during boot. */
  const resolved = ref(false)
  /**
   * True between a successful password check and a verified second factor (ADR-0026).
   *
   * Independent of `user`, which stays null while a factor is owed: the pending access token
   * carries `roles: []` and the MfaStageVoter denies everything but the verify endpoints, so
   * `/me` would 403. The router holds the caller on the MFA screen until this clears.
   */
  const mfaPending = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const permissions = computed(() => new Set(user.value?.permissions ?? []))
  const roles = computed(() => new Set(user.value?.roles ?? []))

  function can(permission: string): boolean {
    return permissions.value.has(permission)
  }

  function hasRole(role: string): boolean {
    return roles.value.has(role)
  }

  /**
   * Load the current user, if there is one.
   *
   * A 401 here is a normal outcome, not an error: it is how the app discovers nobody is
   * signed in.
   */
  async function load(): Promise<void> {
    loading.value = true
    try {
      user.value = await authApi.me()
    } catch {
      user.value = null
    } finally {
      loading.value = false
      resolved.value = true
    }
  }

  async function signIn(username: string, password: string): Promise<void> {
    const session = await authApi.login(username, password)

    if (session.mfaRequired === 'pending') {
      // The password checked out but a second factor is owed. No refresh cookie was set, and
      // the pending access token cannot reach /me, so the store stays unloaded and the
      // router moves the caller to the verify screen. `isAuthenticated` is deliberately false.
      mfaPending.value = true
      return
    }

    // A full session. Reload rather than trusting the login response: /me is the single source
    // of truth for roles and permissions, and it reflects grants made since the token was minted.
    mfaPending.value = false
    await load()
  }

  /** Prove a TOTP and complete the pending session. Throws on a wrong code; the view shows it. */
  async function verifyMfa(code: string): Promise<void> {
    await authApi.verifyMfa(code)
    mfaPending.value = false
    await load()
  }

  /** Spend a recovery code in place of a TOTP. Single-use; throws if the code is spent or invalid. */
  async function verifyMfaRecovery(code: string): Promise<void> {
    await authApi.verifyMfaRecovery(code)
    mfaPending.value = false
    await load()
  }

  async function signOut(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      // Cleared even if the request failed. A client that cannot reach the server must still
      // be able to stop presenting itself as signed in.
      user.value = null
      mfaPending.value = false
    }
  }

  async function signOutEverywhere(): Promise<number> {
    const result = await authApi.logoutEverywhere()
    user.value = null
    mfaPending.value = false
    return result.sessionsRevoked
  }

  /** Called by the API client when a refresh fails: the session is genuinely over. */
  function onSessionLost(): void {
    user.value = null
    resolved.value = true
    // A pending caller's refresh always fails (no refresh cookie was issued), so the client
    // calls this on every verify 401 too. Clearing `mfaPending` here would strand the caller
    // off the MFA screen on a wrong code, so it is left untouched: `mfaPending` is the user's
    // intent to complete a factor, not a live session the lost-session path owns.
  }

  setUnauthenticatedHandler(onSessionLost)

  return {
    user,
    loading,
    resolved,
    mfaPending,
    isAuthenticated,
    can,
    hasRole,
    load,
    signIn,
    verifyMfa,
    verifyMfaRecovery,
    signOut,
    signOutEverywhere,
    onSessionLost,
  }
})
