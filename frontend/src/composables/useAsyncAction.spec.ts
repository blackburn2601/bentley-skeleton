import { describe, expect, it, vi } from 'vitest'

import { useAsyncAction } from '@/composables/useAsyncAction'

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), fromError: vi.fn(), info: vi.fn(), error: vi.fn() }),
}))

describe('useAsyncAction', () => {
  it('reports success for an action that returns nothing', async () => {
    const { run } = useAsyncAction()

    const result = await run(async () => undefined, 'done')

    // The regression this guards: when run() returned `T | undefined`, a void action was
    // indistinguishable from a failure, so callers doing `if (await run(...))` silently
    // skipped their reload. A role toggle then kept re-sending "assign" against stale state.
    expect(result.ok).toBe(true)
  })

  it('carries the value through on success', async () => {
    const { run } = useAsyncAction()

    const result = await run(async () => ({ id: 'abc' }), 'done')

    expect(result).toEqual({ ok: true, value: { id: 'abc' } })
  })

  it('reports failure when the action throws', async () => {
    const { run } = useAsyncAction()

    const result = await run(async () => {
      throw new Error('refused')
    }, 'done')

    expect(result.ok).toBe(false)
  })

  it('always clears busy, including when the action throws', async () => {
    const { busy, run } = useAsyncAction()

    await run(async () => {
      throw new Error('refused')
    }, 'done')

    expect(busy.value).toBe(false)
  })
})
