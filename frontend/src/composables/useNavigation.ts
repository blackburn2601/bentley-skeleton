import { computed, type ComputedRef } from 'vue'

import { navigation, type NavSection } from '@/navigation'
import { useAuthStore } from '@/stores/auth'

/**
 * The navigation this caller may see.
 *
 * A computed over the auth store, deliberately not the `v-can` directive. `v-can` runs once on
 * `mounted` and calls `el.remove()`, so an element it removes never comes back — which makes it
 * unable to express the one behaviour this application most needs to demonstrate: a permission
 * granted by an administrator taking effect on the very next request, with no re-login
 * (INV-13, ADR-0011). Reload `/me`, and this recomputes.
 *
 * Sections that end up empty are dropped, so a caller with no access to a whole area does not
 * see its heading floating over nothing.
 */
export function useNavigation(): ComputedRef<NavSection[]> {
  const auth = useAuthStore()

  return computed(() =>
    navigation
      .map((section) => ({
        ...section,
        items: section.items.filter(
          (item) => item.permission === undefined || auth.can(item.permission),
        ),
      }))
      .filter((section) => section.items.length > 0),
  )
}
