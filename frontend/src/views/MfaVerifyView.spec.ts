import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'

import MfaVerifyView from './MfaVerifyView.vue'
import * as authApi from '@/api/auth'

/**
 * The half-authenticated verify screen (ADR-0026).
 *
 * Reached only by a caller who owes a second factor; the router guard keeps everyone else out.
 * The view itself does no gating — it collects a code and hands it to the store — so these tests
 * pin the two modes (TOTP, recovery), the toggle between them, and that a verified code lands
 * the caller where the sign-in screen pointed them.
 */
vi.mock('@/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  logoutEverywhere: vi.fn(),
  me: vi.fn(),
  verifyMfa: vi.fn(),
  verifyMfaRecovery: vi.fn(),
}))

const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0))

function meFixture() {
  return { id: 'a-user', username: 'someone', roles: [], permissions: [], mfaEnrolled: true, mfaRequired: false }
}

function loginVerified(): authApi.LoginResponse {
  return { id: 'a-user', username: 'someone', roles: [], mfaRequired: 'verified' }
}

// Stub OtpInput so a six-digit code is a single input value rather than six boxes. The real
// component's paste-and-advance behaviour is unit-tested through its own surface; here it is
// the verify flow that matters.
const OtpInputStub = defineComponent({
  name: 'OtpInput',
  props: ['modelValue'],
  emits: ['update:modelValue'],
  template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" data-testid="otp" />',
})

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'mfa', component: { template: '<div />' } },
      { path: '/account', name: 'account', component: { template: '<div />' } },
    ],
  })
}

describe('MfaVerifyView', () => {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  let wrapper: any
  let router: ReturnType<typeof makeRouter>

  beforeEach(async () => {
    vi.clearAllMocks()
    vi.mocked(authApi.me).mockResolvedValue(meFixture())

    router = makeRouter()
    await router.push('/')

    wrapper = mount(MfaVerifyView, {
      global: {
        plugins: [createPinia(), router],
        stubs: { OtpInput: OtpInputStub },
      },
    })
  })

  afterEach(() => {
    wrapper?.unmount()
  })

  it('renders the TOTP entry by default and offers the recovery toggle', () => {
    expect(wrapper.text()).toContain('Authenticator-Code')
    expect(wrapper.text()).toContain('Stattdessen einen Wiederherstellungscode verwenden')
  })

  it('switches to recovery mode and back via the footer toggle', async () => {
    const toggle = () =>
      wrapper.findAll('button').find((b: { text: () => string }) => b.text().includes('Stattdessen'))!

    await toggle().trigger('click')
    expect(wrapper.text()).toContain('Wiederherstellungscode')
    expect(wrapper.text()).toContain('Stattdessen den Authenticator-Code verwenden')

    await toggle().trigger('click')
    expect(wrapper.text()).toContain('Authenticator-Code')
  })

  it('verifies a TOTP and lands on the account page', async () => {
    vi.mocked(authApi.verifyMfa).mockResolvedValue(loginVerified())

    await wrapper.find('[data-testid="otp"]').setValue('123456')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(authApi.verifyMfa).toHaveBeenCalledWith('123456')
    expect(router.currentRoute.value.name).toBe('account')
  })

  it('honours a ?redirect destination after verifying a TOTP', async () => {
    await router.push({ name: 'mfa', query: { redirect: '/admin/users' } })
    vi.mocked(authApi.verifyMfa).mockResolvedValue(loginVerified())

    await wrapper.find('[data-testid="otp"]').setValue('123456')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(router.currentRoute.value.fullPath).toBe('/admin/users')
  })

  it('verifies via a recovery code', async () => {
    vi.mocked(authApi.verifyMfaRecovery).mockResolvedValue(loginVerified())

    const toggle = wrapper.findAll('button').find((b: { text: () => string }) => b.text().includes('Stattdessen'))!
    await toggle.trigger('click')

    await wrapper.find('#recovery-code').setValue('RECOVERY-1')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(authApi.verifyMfaRecovery).toHaveBeenCalledWith('RECOVERY-1')
    expect(router.currentRoute.value.name).toBe('account')
  })

  it('clears the entry and stays on the screen when the code is wrong', async () => {
    vi.mocked(authApi.verifyMfa).mockRejectedValue(new Error('Code ungültig.'))

    await wrapper.find('[data-testid="otp"]').setValue('000000')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(wrapper.text()).toContain('Code ungültig.')
    expect(router.currentRoute.value.name).toBe('mfa')
    const entry = wrapper.find('[data-testid="otp"]').element as HTMLInputElement
    expect(entry.value).toBe('')
  })
})