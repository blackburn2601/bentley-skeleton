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
    await authApi.login(username, password)
    // Reload rather than trusting the login response: /me is the single source of truth for
    // roles and permissions, and it reflects grants made since the token was minted.
    await load()
  }

  async function signOut(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      // Cleared even if the request failed. A client that cannot reach the server must still
      // be able to stop presenting itself as signed in.
      user.value = null
    }
  }

  async function signOutEverywhere(): Promise<number> {
    const result = await authApi.logoutEverywhere()
    user.value = null
    return result.sessionsRevoked
  }

  /** Called by the API client when a refresh fails: the session is genuinely over. */
  function onSessionLost(): void {
    user.value = null
    resolved.value = true
  }

  setUnauthenticatedHandler(onSessionLost)

  return {
    user,
    loading,
    resolved,
    isAuthenticated,
    can,
    hasRole,
    load,
    signIn,
    signOut,
    signOutEverywhere,
    onSessionLost,
  }
})
