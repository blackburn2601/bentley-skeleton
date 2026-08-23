import { api } from '@/api/client'
import { toQuery, type Paginated } from '@/api/pagination'

/** Mirrors ListSecurityEventsResponse field for field. */
export interface AdminSecurityEvent {
  id: string
  type: string
  occurredAt: string
  actorId: string | null
  ipAddress: string | null
  requestId: string | null
  highSeverity: boolean
}

export interface ListAuditEventsQuery {
  page?: number
  perPage?: number
  type?: string
}

export const listAuditEvents = (query: ListAuditEventsQuery = {}) =>
  api.get<Paginated<AdminSecurityEvent>>(`/api/v1/admin/audit-events${toQuery(query)}`)

/** `refresh_token_reuse` reads badly in a table; this is display only. */
export const humaniseEventType = (type: string): string =>
  type.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())
