import { api } from './client'

/** One module per topic, mirroring the backend's single-responsibility rule. */

export interface Me {
  id: string
  email: string
  emailVerified: boolean
  mfaEnabled: boolean
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

export const login = (email: string, password: string) =>
  api.post<{ id: string; email: string; roles: string[] }>('/api/v1/auth/login', { email, password })

export const logout = () => api.post<void>('/api/v1/auth/logout')

export const logoutEverywhere = () =>
  api.post<{ sessionsRevoked: number }>('/api/v1/auth/logout-all')

export const me = () => api.get<Me>('/api/v1/auth/me')

export const register = (email: string, password: string) =>
  api.post<{ message: string }>('/api/v1/auth/register', { email, password })

export const verifyEmail = (token: string) =>
  api.post<{ message: string }>('/api/v1/auth/verify-email', { token })

export const requestPasswordReset = (email: string) =>
  api.post<{ message: string }>('/api/v1/auth/password/forgot', { email })

export const resetPassword = (token: string, password: string) =>
  api.post<{ message: string }>('/api/v1/auth/password/reset', { token, password })

export const listSessions = () => api.get<{ sessions: Session[] }>('/api/v1/auth/sessions')

export const exportMyData = () => api.post<unknown>('/api/v1/me/export')

export const eraseMyAccount = () =>
  api.delete<{ erased: boolean; sessionsRevoked: number }>('/api/v1/me')
