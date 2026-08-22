import type { Directive } from 'vue'

import { useAuthStore } from '@/stores/auth'

/**
 * `v-can="'user.delete'"` — hide an element the caller has no class-level permission for.
 *
 * **Display only** (INV-16). It removes the element from the DOM, which stops a user clicking
 * something that would fail — and stops nothing else. Anyone can issue the request directly;
 * the server is what refuses it.
 *
 * Removes rather than hides with CSS: an element that is merely invisible is still in the
 * page, still focusable by keyboard, and still readable by anyone who opens devtools — which
 * makes it look like a security control while being less than one.
 */
export const vCan: Directive<HTMLElement, string> = {
  mounted(el, binding) {
    const auth = useAuthStore()

    if (!auth.can(binding.value)) {
      el.remove()
    }
  },
}
