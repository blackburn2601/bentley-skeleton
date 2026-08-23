import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { useNavigation } from '@/composables/useNavigation'
import { useAuthStore } from '@/stores/auth'
import type { Me } from '@/api/auth'

/**
 * The navigation must react to a permission change, not just render once.
 *
 * This is the unit-level half of INV-13: an administrator grants a permission, `/me` is
 * re-read, and the sidebar gains an entry without the component remounting and without the
 * user signing in again. The `v-can` directive cannot do this — it runs on `mounted` and
 * calls `el.remove()` — which is why the shell uses this composable instead.
 */
function signIn(permissions: string[]): void {
  const auth = useAuthStore()
  auth.user = {
    id: 'a-user',
    email: 'someone@example.test',
    emailVerified: true,
    mfaEnabled: false,
    roles: [],
    permissions,
  } as Me
}

describe('useNavigation', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hides every entry the caller has no permission for', () => {
    signIn([])
    const sections = useNavigation()

    // The dashboard needs no permission, so it is the only thing left — and its section is
    // the only one that survives.
    expect(sections.value.flatMap((s) => s.items).map((i) => i.label)).toEqual(['Dashboard'])
  })

  it('drops a section entirely when nothing in it is visible', () => {
    signIn([])

    expect(useNavigation().value.map((s) => s.label)).not.toContain('Compliance')
  })

  it('shows exactly what the permission set allows', () => {
    // The fixtures' editor@: ROLE_AUDITOR through the support group.
    signIn(['audit.read', 'user.read'])

    expect(useNavigation().value.flatMap((s) => s.items).map((i) => i.label)).toEqual([
      'Dashboard',
      'Users',
      'Audit log',
    ])
  })

  it('gains an entry when a permission is granted, without remounting', () => {
    signIn(['user.read'])
    const sections = useNavigation()

    expect(sections.value.flatMap((s) => s.items).map((i) => i.label)).not.toContain('Roles')

    // What auth.load() does after an administrator grants the permission.
    signIn(['user.read', 'role.read'])

    expect(sections.value.flatMap((s) => s.items).map((i) => i.label)).toContain('Roles')
  })
})
