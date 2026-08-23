/**
 * The envelope every collection in this API returns (ADR-0019).
 *
 * One type, so a client writes one pager. `total` is the ACL-filtered count — the number of
 * rows this caller may see, not the number that exist.
 */
export interface Paginated<T> {
  items: T[]
  page: number
  perPage: number
  total: number
}

/**
 * Build a query string, dropping anything the server should treat as absent.
 *
 * Empty values are omitted rather than sent blank: `?q=` is a filter matching everything,
 * which is not the same request as no filter at all, and the two would page differently.
 *
 * Generic over the caller's own query type rather than taking a `Record` with an index
 * signature: a plain `interface` is not assignable to an index-signature type, so every
 * endpoint's query interface would have had to declare `[key: string]` and lose its precision.
 */
export function toQuery<T extends object>(params: T): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params) as [string, unknown][]) {
    if (value === undefined || value === null || value === '') {
      continue
    }

    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      search.set(key, String(value))
    }
  }

  const query = search.toString()

  return query === '' ? '' : `?${query}`
}
