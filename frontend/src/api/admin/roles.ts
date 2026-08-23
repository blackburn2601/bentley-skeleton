import { api } from '@/api/client'
import type { Paginated } from '@/api/pagination'

/** Mirrors ListRolesResponse field for field. */
export interface AdminRole {
  id: string
  name: string
  description: string | null
  permissions: string[]
}

export const listRoles = () => api.get<Paginated<AdminRole>>('/api/v1/admin/roles')

export const createRole = (name: string, description: string | null) =>
  api.post<AdminRole>('/api/v1/admin/roles', { name, description })

/** Only the description. Role names are in every access token, so renaming one is not offered. */
export const updateRole = (id: string, description: string | null) =>
  api.patch<AdminRole>(`/api/v1/admin/roles/${id}`, { description })

/** Replaces the whole set. A delta would race with another administrator's open screen. */
export const setRolePermissions = (id: string, permissions: string[]) =>
  api.put<AdminRole>(`/api/v1/admin/roles/${id}/permissions`, { permissions })

export const deleteRole = (id: string) =>
  api.delete<{ name: string; deleted: boolean }>(`/api/v1/admin/roles/${id}`)
