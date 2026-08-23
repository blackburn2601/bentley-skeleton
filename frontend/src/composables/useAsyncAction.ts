import { ref } from 'vue'

import { useToast } from '@/composables/useToast'

/**
 * Run a write, report what happened, and never leave a button spinning.
 *
 * Every admin mutation needs the same four things: a busy flag, a success toast, an error
 * toast carrying the server's request id, and a guarantee that `busy` is cleared even when the
 * request throws. Writing that inline on each screen is how one of them ends up missing the
 * `finally`.
 */
/**
 * The outcome of a write.
 *
 * Deliberately NOT `T | undefined`. That shape conflates "the action failed" with "the action
 * succeeded and returned nothing", so a caller doing `if (await run(...))` silently skips its
 * follow-up whenever the action is void — which is exactly how a role toggle ended up never
 * reloading, leaving the screen showing stale state and turning every click into an assign.
 */
export type ActionResult<T> = { ok: true; value: T } | { ok: false }

export function useAsyncAction() {
  const busy = ref(false)
  const toast = useToast()

  async function run<T>(
    action: () => Promise<T>,
    successMessage: string,
  ): Promise<ActionResult<T>> {
    busy.value = true

    try {
      const value = await action()
      toast.success(successMessage)

      return { ok: true, value }
    } catch (error) {
      // The server's message is the useful one — it says *why* it refused, which for these
      // endpoints is usually a deliberate guardrail rather than a bug.
      toast.fromError(error)

      return { ok: false }
    } finally {
      busy.value = false
    }
  }

  return { busy, run }
}
