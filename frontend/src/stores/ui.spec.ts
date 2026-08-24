import { beforeEach, describe, expect, it } from 'vitest'

import { createPinia, setActivePinia } from 'pinia'

import { useUiStore } from './ui'

describe('useUiStore — request counter', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('starts at zero', () => {
    const ui = useUiStore()
    expect(ui.pendingRequests).toBe(0)
  })

  it('counts up and down for paired begin/end', () => {
    const ui = useUiStore()

    ui.beginRequest()
    ui.beginRequest()
    expect(ui.pendingRequests).toBe(2)

    ui.endRequest()
    expect(ui.pendingRequests).toBe(1)

    ui.endRequest()
    expect(ui.pendingRequests).toBe(0)
  })

  it('never goes below zero, even on an unmatched end', () => {
    // A `finally` path that calls end without a matching start — e.g. a handler registered
    // after a request already began — must not leave the counter negative, or the bar would
    // never show again.
    const ui = useUiStore()

    ui.endRequest()
    expect(ui.pendingRequests).toBe(0)

    ui.beginRequest()
    expect(ui.pendingRequests).toBe(1)
  })
})