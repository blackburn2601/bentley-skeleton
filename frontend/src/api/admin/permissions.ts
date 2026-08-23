import { api } from '@/api/client'
import type { Paginated } from '@/api/pagination'

/** Mirrors ListPermissionsResponse field for field. */
export interface AdminPermission {
  id: string
  name: string
  resource: string
  action: string
}

export const listPermissions = () => api.get<Paginated<AdminPermission>>('/api/v1/admin/permissions')
