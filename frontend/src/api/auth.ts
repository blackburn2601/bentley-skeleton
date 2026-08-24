import { api } from './client'

/** One module per topic, mirroring the backend's single-responsibility rule. */

export interface Me {
  id: string
  username: string
  roles: string[]
  permissions: string[]
}

export interface Session {
  id: string
  createdAt: string
  ipAddress: string | null
  userAgent: string | null
  current: boolean
}

export const login = (username: string, password: string) =>
  api.post<{ id: string; username: string; roles: string[] }>('/api/v1/auth/login', {
    username,
    password,
  })

export const logout = () => api.post<void>('/api/v1/auth/logout')

export const logoutEverywhere = () =>
  api.post<{ sessionsRevoked: number }>('/api/v1/auth/logout-all')

export const me = () => api.get<Me>('/api/v1/auth/me')

/**
 * Change the signed-in user's own password.
 *
 * The current session stays valid — this is not a recovery flow, just a self-service password
 * change. Requires the CSRF header (double-submit), which `client.ts` attaches for every
 * mutating request.
 */
export const changePassword = (currentPassword: string, newPassword: string) =>
  api.post<void>('/api/v1/auth/change-password', { currentPassword, newPassword })

export const listSessions = () => api.get<{ sessions: Session[] }>('/api/v1/auth/sessions')

export const exportMyData = () => api.post<unknown>('/api/v1/me/export')

export const eraseMyAccount = () =>
  api.delete<{ erased: boolean; sessionsRevoked: number }>('/api/v1/me')