import { computed, type ComputedRef } from 'vue'

import { useAuthStore } from '@/stores/auth'

/**
 * Whether the UI should offer an action.
 *
 * **Not an authorization boundary** (INV-16). This hides controls the server would refuse
 * anyway, which is a courtesy to the user — it is not protection, and nothing should be built
 * as though it were. The endpoint enforces the same permission; this only decides whether the
 * button is worth showing.
 *
 * Class-level only, by construction: the store's permission list comes from
 * AclFacade::classLevelPermissionsOf(), which deliberately cannot enumerate object-level
 * grants. "May edit THIS document" is not answerable in the browser and must not be guessed.
 */
export function usePermission(permission: string): ComputedRef<boolean> {
  const auth = useAuthStore()
  return computed(() => auth.can(permission))
}

export function useAnyPermission(...permissions: string[]): ComputedRef<boolean> {
  const auth = useAuthStore()
  return computed(() => permissions.some((permission) => auth.can(permission)))
}
