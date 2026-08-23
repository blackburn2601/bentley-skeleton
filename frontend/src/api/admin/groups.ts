import { api } from '@/api/client'
import type { Paginated } from '@/api/pagination'

/** Mirrors ListGroupsResponse field for field. */
export interface AdminGroup {
  id: string
  name: string
  description: string | null
  roles: string[]
  memberCount: number
}

export const listGroups = () => api.get<Paginated<AdminGroup>>('/api/v1/admin/groups')

/** What create/update/set-* return: the group without its member count. */
export interface AdminGroupDetail {
  id: string
  name: string
  description: string | null
  roles: string[]
}

export const createGroup = (name: string, description: string | null) =>
  api.post<AdminGroupDetail>('/api/v1/admin/groups', { name, description })

export const updateGroup = (id: string, name: string, description: string | null) =>
  api.patch<AdminGroupDetail>(`/api/v1/admin/groups/${id}`, { name, description })

export const setGroupRoles = (id: string, roles: string[]) =>
  api.put<AdminGroupDetail>(`/api/v1/admin/groups/${id}/roles`, { roles })

export const setGroupMembers = (id: string, members: string[]) =>
  api.put<AdminGroupDetail>(`/api/v1/admin/groups/${id}/members`, { members })

export interface GroupMember {
  id: string
  email: string
}

/** The current membership, so the members picker opens with the right boxes ticked. */
export const listGroupMembers = (id: string) =>
  api.get<Paginated<GroupMember>>(`/api/v1/admin/groups/${id}/members`)

export const deleteGroup = (id: string) =>
  api.delete<{ name: string; deleted: boolean }>(`/api/v1/admin/groups/${id}`)
