import { api } from '@/api/client'
import { toQuery, type Paginated } from '@/api/pagination'

/**
 * The user administration endpoints.
 *
 * One module per topic, mirroring the backend's single-topic rule, and every call goes through
 * `client.ts` — a bare fetch() skips the single-flight refresh, and concurrent refreshes
 * present already-rotated tokens, trip reuse detection and sign the user out everywhere.
 */
export type UserStatus = 'active' | 'suspended' | 'anonymised'

/** Mirrors ListUsersResponse field for field. */
export interface AdminUser {
  id: string
  username: string
  status: UserStatus
  lockedUntil: string | null
  createdAt: string
}

export interface ListUsersQuery {
  page?: number
  perPage?: number
  q?: string
  status?: UserStatus
}

export const listUsers = (query: ListUsersQuery = {}) =>
  api.get<Paginated<AdminUser>>(`/api/v1/admin/users${toQuery(query)}`)

/** The wire values, with labels, for a status filter. */
export const USER_STATUSES: { value: UserStatus; label: string }[] = [
  { value: 'active', label: 'Aktiv' },
  { value: 'suspended', label: 'Gesperrt' },
  { value: 'anonymised', label: 'Anonymisiert' },
]

/**
 * Mirrors DescribeUserResponse field for field.
 *
 * `security` and `access` are nested because they answer two different questions — "why can
 * this person not sign in?" and "what may this person do?".
 */
export interface AdminUserDetail {
  id: string
  username: string
  status: UserStatus
  createdAt: string
  /**
   * The counter behind "a grant takes effect on the next request" (ADR-0011). Shown because a
   * claim you can watch change is worth more than one in a docblock.
   */
  aclVersion: number
  security: {
    failedLoginCount: number
    lockedUntil: string | null
    passwordChangedAt: string
    /** Whether the user has a live TOTP factor (ADR-0026). Advisory; the enrol endpoint re-checks. */
    mfaEnrolled: boolean
    /** Whether an administrator has enforced MFA on this account, independently of enrollment. */
    mfaRequired: boolean
  }
  access: {
    /** Assigned directly. Roles inherited through a group are not listed here. */
    roles: string[]
    groups: string[]
    /** What this person can actually do — direct roles plus everything from their groups. */
    effectivePermissions: string[]
  }
}

export const describeUser = (id: string) =>
  api.get<AdminUserDetail>(`/api/v1/admin/users/${id}`)

export const updateUser = (id: string, username: string) =>
  api.patch<{ id: string; username: string }>(`/api/v1/admin/users/${id}`, { username })

export const revokeUserSessions = (id: string) =>
  api.post<{ sessionsRevoked: number }>(`/api/v1/admin/users/${id}/sessions/revoke`)

export interface CreatedUser {
  id: string
  username: string
  status: UserStatus
  /**
   * A one-time temporary password. The API returns it exactly once and never again — it is not
   * persisted server-side. The SPA must show it immediately with a Copy button, or it is lost
   * and only an admin reset can recover it.
   */
  temporaryPassword: string
}

/**
 * Create an account. No password field, deliberately — the API generates a one-time temporary
 * password and returns it here. Nobody else ever sees it again, so hand it to the user now.
 */
export const createUser = (username: string) =>
  api.post<CreatedUser>('/api/v1/admin/users', { username })

/**
 * Admin-initiated password reset. Returns a fresh one-time temporary password that must be shown
 * immediately — it is never returned again.
 */
export const resetUserPassword = (id: string) =>
  api.post<{ id: string; username: string; temporaryPassword: string }>(
    `/api/v1/admin/users/${id}/password`,
  )

export const changeUserStatus = (id: string, status: UserStatus) =>
  api.patch<{ id: string; status: UserStatus }>(`/api/v1/admin/users/${id}/status`, { status })

/** Anonymises rather than deletes: the audit trail has to outlive the erasure it records. */
export const eraseUser = (id: string) =>
  api.delete<{ erased: boolean; sessionsRevoked: number }>(`/api/v1/admin/users/${id}`)

export const assignRole = (id: string, role: string) =>
  api.post<{ userId: string; role: string }>(`/api/v1/admin/users/${id}/roles`, { role })

export const revokeRole = (id: string, role: string) =>
  api.delete<{ userId: string; role: string }>(`/api/v1/admin/users/${id}/roles/${role}`)