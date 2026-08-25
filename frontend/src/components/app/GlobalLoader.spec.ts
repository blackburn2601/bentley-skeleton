import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'

import GlobalLoader from './GlobalLoader.vue'
import { useUiStore } from '@/stores/ui'

describe('GlobalLoader', () => {
  let wrapper: ReturnType<typeof mount> | undefined

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
  })

  afterEach(() => {
    // The bar teleports to <body>; a component left mounted by a previous test would leave its
    // bar behind and the next test's `bar()` would find the stale one. Unmount and clear.
    wrapper?.unmount()
    wrapper = undefined
    document.body.innerHTML = ''
    vi.useRealTimers()
  })

  // The component teleports its bar to <body>, so it is never inside the wrapper's root — query
  // the document instead.
  const bar = () => document.body.querySelector('[role="progressbar"]')

  it('stays hidden for an instant request and only appears after the grace period', async () => {
    const ui = useUiStore()
    wrapper = mount(GlobalLoader)

    ui.beginRequest()
    // The watch runs in a microtask, so flush it before advancing the fake clock — otherwise
    // the timer is never scheduled and the bar never appears.
    await nextTick()
    vi.advanceTimersByTime(199)
    await nextTick()
    expect(bar()).toBeNull()

    // Crossing 200 ms while still in flight flips it on.
    vi.advanceTimersByTime(1)
    await nextTick()
    expect(bar()).not.toBeNull()
  })

  it('hides the instant the request count reaches zero', async () => {
    const ui = useUiStore()
    wrapper = mount(GlobalLoader)

    ui.beginRequest()
    await nextTick()
    vi.advanceTimersByTime(200)
    await nextTick()
    expect(bar()).not.toBeNull()

    ui.endRequest()
    await nextTick()
    expect(bar()).toBeNull()
  })

  it('does not show if the request finishes before the grace period', async () => {
    // The regression this guards: a naive timer would set `visible = true` after the request
    // had already ended, leaving a stray bar with nothing loading.
    const ui = useUiStore()
    wrapper = mount(GlobalLoader)

    ui.beginRequest()
    await nextTick()
    vi.advanceTimersByTime(100)
    ui.endRequest()
    await nextTick()
    vi.advanceTimersByTime(200)
    await nextTick()

    expect(bar()).toBeNull()
  })
})