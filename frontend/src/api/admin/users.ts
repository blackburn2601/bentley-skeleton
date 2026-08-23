import { api } from '@/api/client'
import { toQuery, type Paginated } from '@/api/pagination'

/**
 * The user administration endpoints.
 *
 * One module per topic, mirroring the backend's single-topic rule, and every call goes through
 * `client.ts` — a bare fetch() skips the single-flight refresh, and concurrent refreshes
 * present already-rotated tokens, trip reuse detection and sign the user out everywhere.
 */
export type UserStatus = 'pending_verification' | 'active' | 'suspended' | 'anonymised'

/** Mirrors ListUsersResponse field for field. */
export interface AdminUser {
  id: string
  email: string
  status: UserStatus
  emailVerified: boolean
  mfaEnabled: boolean
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
  { value: 'active', label: 'Active' },
  { value: 'pending_verification', label: 'Pending verification' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'anonymised', label: 'Anonymised' },
]

/**
 * Mirrors DescribeUserResponse field for field.
 *
 * `security` and `access` are nested because they answer two different questions — "why can
 * this person not sign in?" and "what may this person do?".
 */
export interface AdminUserDetail {
  id: string
  email: string
  status: UserStatus
  emailVerified: boolean
  mfaEnabled: boolean
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

/** Changing the address resets verification: the new one is unproven until mail reaches it. */
export const updateUser = (id: string, email: string) =>
  api.patch<{ id: string; email: string; emailVerified: boolean }>(
    `/api/v1/admin/users/${id}`,
    { email },
  )

export const revokeUserSessions = (id: string) =>
  api.post<{ sessionsRevoked: number }>(`/api/v1/admin/users/${id}/sessions/revoke`)

export interface CreatedUser {
  id: string
  email: string
  status: UserStatus
  /** The account has no usable password until the holder follows the emailed link. */
  passwordSetupEmailed: boolean
}

/**
 * Create an account. No password field, deliberately — the new user sets their own through
 * the link this sends, so no administrator ever knows it.
 */
export const createUser = (email: string) =>
  api.post<CreatedUser>('/api/v1/admin/users', { email })

export const changeUserStatus = (id: string, status: UserStatus) =>
  api.patch<{ id: string; status: UserStatus }>(`/api/v1/admin/users/${id}/status`, { status })

/** Anonymises rather than deletes: the audit trail has to outlive the erasure it records. */
export const eraseUser = (id: string) =>
  api.delete<{ erased: boolean; sessionsRevoked: number }>(`/api/v1/admin/users/${id}`)

export const assignRole = (id: string, role: string) =>
  api.post<{ userId: string; role: string }>(`/api/v1/admin/users/${id}/roles`, { role })

export const revokeRole = (id: string, role: string) =>
  api.delete<{ userId: string; role: string }>(`/api/v1/admin/users/${id}/roles/${role}`)
