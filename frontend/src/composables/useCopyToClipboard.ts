import { useToast } from '@/composables/useToast'

/**
 * Copy a value, and say whether it worked.
 *
 * `navigator.clipboard` is not always there to be called: it throws in an insecure context,
 * and a browser may refuse the write outright. A copy button that silently does nothing is
 * worse than no button, because the operator walks away believing they hold the value — so a
 * failure has to be visible, and it has to name where the value can still be read from.
 *
 * Returns the outcome as well as toasting it, for a caller that also flips a button label.
 */
export function useCopyToClipboard() {
  const toast = useToast()

  async function copy(value: string, successMessage: string, whereToFind: string): Promise<boolean> {
    try {
      await navigator.clipboard.writeText(value)
      toast.success(successMessage)

      return true
    } catch {
      toast.error('Kopieren nicht möglich.', whereToFind)

      return false
    }
  }

  return { copy }
}
