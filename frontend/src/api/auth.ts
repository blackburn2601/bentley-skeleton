import { api } from './client'

/** One module per topic, mirroring the backend's single-responsibility rule. */

export interface Me {
  id: string
  username: string
  roles: string[]
  permissions: string[]
  mfaEnrolled: boolean
  mfaRequired: boolean
}

export interface Session {
  id: string
  createdAt: string
  ipAddress: string | null
  userAgent: string | null
  current: boolean
}

/**
 * The MFA state a login/verify response carries (ADR-0026).
 *
 * `false` is a floor user with a full session; `'pending'` means a second factor is owed and no
 * refresh cookie was set; `'verified'` is a full session reached after proving a factor. The
 * client branches on a value, never on a missing key.
 */
export type MfaRequired = false | 'pending' | 'verified'

export interface LoginResponse {
  id: string
  username: string
  roles: string[]
  mfaRequired: MfaRequired
}

export interface MfaEnrollment {
  secret: string
  provisioningUri: string
  qrDataUrl: string
}

export const login = (username: string, password: string) =>
  api.post<LoginResponse>('/api/v1/auth/login', {
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

// --- Zwei-Faktor-Authentifizierung (ADR-0026) -----------------------------------------------

/**
 * Prove a second factor and complete the pending session.
 *
 * Returns the same shape as `login` once verified; `mfaRequired` is `'verified'`. A wrong code
 * is a 401 problem, which the API client surfaces as an `ApiError` for the view to show.
 */
export const verifyMfa = (code: string) =>
  api.post<LoginResponse>('/api/v1/auth/mfa/verify', { code })

/** Burn one recovery code in place of a TOTP. Single-use; the same code cannot be spent twice. */
export const verifyMfaRecovery = (code: string) =>
  api.post<LoginResponse>('/api/v1/auth/mfa/recovery/verify', { code })

/**
 * Provision a *provisional* TOTP secret for the signed-in owner.
 *
 * The secret is not live until `confirmMfa` proves a working authenticator, so a caller who
 * abandons enrollment leaves no factor behind. Requires `account.update`.
 */
export const enrolMfa = () => api.post<MfaEnrollment>('/api/v1/account/mfa/enrol', {})

/**
 * Activate the provisional secret with a current code and mint the one-time recovery codes.
 *
 * The recovery codes are returned exactly once; the server keeps only their hashes.
 */
export const confirmMfa = (code: string) =>
  api.post<{ recoveryCodes: string[] }>('/api/v1/account/mfa/confirm', { code })

/** Remove the caller's own factor. The admin-enforced requirement is left untouched. */
export const disableMfa = () => api.delete<void>('/api/v1/account/mfa')

/** An administrator enforces or lifts the MFA requirement on one user. Requires `user.update`. */
export const adminSetMfaRequired = (userId: string, required: boolean) =>
  api.put<{ id: string; mfaRequired: boolean }>(
    `/api/v1/admin/users/${userId}/mfa/required`,
    { required },
  )

/** An administrator strips a user's factor and clears the requirement. Requires `user.update`. */
export const adminResetMfa = (userId: string) =>
  api.post<void>(`/api/v1/admin/users/${userId}/mfa/reset`, {})