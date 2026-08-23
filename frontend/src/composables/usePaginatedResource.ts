import { ref, shallowRef, watch, type Ref } from 'vue'

import type { Paginated } from '@/api/pagination'

/**
 * The list-screen machinery: paging, filtering, loading and error state.
 *
 * Extracted because every admin list needs exactly this, and because views are supposed to
 * render and dispatch rather than decide (docs/cookbook/add-frontend-view.md). Without it the
 * same twenty lines of `loading = true; try { … } finally { loading = false }` appear on every
 * screen and drift apart.
 *
 * Filters are debounced and reset the page to 1 — otherwise typing a search term while on page
 * 4 asks for page 4 of a result set that now has one page, and the screen goes blank.
 */
export interface PaginatedResourceOptions {
  perPage?: number
  debounceMs?: number
}

export function usePaginatedResource<T>(
  fetcher: (params: { page: number; perPage: number }) => Promise<Paginated<T>>,
  filters: Ref<Record<string, string | undefined>> = ref({}),
  options: PaginatedResourceOptions = {},
) {
  const { perPage: initialPerPage = 25, debounceMs = 250 } = options

  const items = shallowRef<T[]>([])
  const page = ref(1)
  const perPage = ref(initialPerPage)
  const total = ref(0)
  const loading = ref(false)
  const error = shallowRef<unknown>(null)

  /**
   * Guards against a slow first request overwriting a fast second one.
   *
   * Type a search term quickly and two requests are in flight; whichever the network returns
   * last wins, which is not necessarily the one the user is waiting for.
   */
  let requestId = 0

  async function load(): Promise<void> {
    const current = ++requestId
    loading.value = true
    error.value = null

    try {
      const result = await fetcher({ page: page.value, perPage: perPage.value })

      if (current !== requestId) {
        return
      }

      items.value = result.items
      total.value = result.total
    } catch (caught) {
      if (current === requestId) {
        error.value = caught
        items.value = []
        total.value = 0
      }
    } finally {
      if (current === requestId) {
        loading.value = false
      }
    }
  }

  watch(page, () => void load())

  let debounce: ReturnType<typeof setTimeout> | undefined
  watch(
    filters,
    () => {
      clearTimeout(debounce)
      debounce = setTimeout(() => {
        if (page.value === 1) {
          void load()
          return
        }

        // Assigning triggers the page watcher, which loads — so do not also load here.
        page.value = 1
      }, debounceMs)
    },
    { deep: true },
  )

  return { items, page, perPage, total, loading, error, load, refresh: load }
}
