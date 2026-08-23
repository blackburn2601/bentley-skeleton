import { ref } from 'vue'

import { ApiError } from '@/api/problem'

/**
 * The app's notifications.
 *
 * Module-level state rather than a Pinia store: a toast is not application state, nothing
 * else reads it, and it must be raisable from anywhere — including a composable that has no
 * component instance to hand.
 *
 * `fromError` exists so failures surface the server's request id. Support cannot correlate
 * "it didn't work" with a log line; it can correlate `X-Request-Id`, which ApiError already
 * carries out of problem+json.
 */
export type ToastVariant = 'default' | 'success' | 'destructive'

export interface Toast {
  id: number
  title: string
  description?: string
  variant: ToastVariant
}

const toasts = ref<Toast[]>([])
let nextId = 0

export function useToast() {
  function push(title: string, description: string | undefined, variant: ToastVariant): void {
    toasts.value = [...toasts.value, { id: nextId++, title, description, variant }]
  }

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  return {
    toasts,
    dismiss,
    success: (title: string, description?: string) => push(title, description, 'success'),
    info: (title: string, description?: string) => push(title, description, 'default'),
    error: (title: string, description?: string) => push(title, description, 'destructive'),

    /** Turn a failed request into a toast that a support ticket can be opened against. */
    fromError(error: unknown, fallback = 'Something went wrong'): void {
      if (error instanceof ApiError) {
        const reference =
          error.requestId === undefined ? undefined : `Reference: ${error.requestId}`
        push(error.message, reference, 'destructive')
        return
      }

      push(fallback, undefined, 'destructive')
    },
  }
}
