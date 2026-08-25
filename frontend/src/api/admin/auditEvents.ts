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
  q?: string
  type?: string
}

export const listAuditEvents = (query: ListAuditEventsQuery = {}) =>
  api.get<Paginated<AdminSecurityEvent>>(`/api/v1/admin/audit-events${toQuery(query)}`)

/**
 * German labels for the audit event types the backend records.
 *
 * Keyed by the wire value, never by a translated string — these are the same identifiers the
 * type filter sends back to the API, so they stay untouched everywhere except here.
 *
 * Deliberately a lookup with a fallback rather than an exhaustive map: the backend enum grows,
 * and a new event type should appear in the table in a readable form rather than blanking the
 * cell or failing a type check on a value the server is already returning.
 */
const EVENT_TYPE_LABELS: Record<string, string> = {
  login_succeeded: 'Anmeldung erfolgreich',
  login_failed: 'Anmeldung fehlgeschlagen',
  account_locked: 'Konto gesperrt',
  logout_succeeded: 'Abmeldung',
  all_sessions_revoked: 'Alle Sitzungen beendet',
  refresh_token_rotated: 'Refresh-Token erneuert',
  refresh_token_reuse: 'Refresh-Token wiederverwendet',
  user_created: 'Benutzer angelegt',
  password_reset: 'Passwort zurückgesetzt',
  password_changed: 'Passwort geändert',
  permission_granted: 'Berechtigung erteilt',
  permission_revoked: 'Berechtigung entzogen',
  role_assigned: 'Rolle zugewiesen',
  role_revoked: 'Rolle entzogen',
  super_admin_access_used: 'Super-Admin-Zugriff genutzt',
  admin_data_accessed: 'Administrative Daten gelesen',
  gdpr_export_requested: 'DSGVO-Export angefordert',
  gdpr_erasure_requested: 'DSGVO-Löschung angefordert',
}

/** `refresh_token_reuse` reads badly in a table; this is display only. */
export const humaniseEventType = (type: string): string =>
  EVENT_TYPE_LABELS[type] ?? type.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase())
