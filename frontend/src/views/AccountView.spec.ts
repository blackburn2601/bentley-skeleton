import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'

import AccountView from './AccountView.vue'
import { useAuthStore } from '@/stores/auth'
import * as authApi from '@/api/auth'

/**
 * The self-service enrollment flow (ADR-0026).
 *
 * A provisional secret is provisioned, the user scans a QR with an authenticator, proves it
 * with a current code, and the server mints one-time recovery codes. The secret is never live
 * until the confirm step, so the test cares about the observable sequence: the QR and secret
 * appear after enrol, the recovery codes appear exactly once after confirm, and the screen
 * flips from "Einrichten" to "Deaktivieren" once the factor is live.
 */
vi.mock('@/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  logoutEverywhere: vi.fn(),
  me: vi.fn(),
  verifyMfa: vi.fn(),
  verifyMfaRecovery: vi.fn(),
  enrolMfa: vi.fn(),
  confirmMfa: vi.fn(),
  disableMfa: vi.fn(),
  adminSetMfaRequired: vi.fn(),
  adminResetMfa: vi.fn(),
}))

const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0))

function meFixture(enrolled = false) {
  return {
    id: 'a-user',
    username: 'someone',
    roles: [],
    permissions: [],
    mfaEnrolled: enrolled,
    mfaRequired: false,
  }
}

// The reka-ui Dialog teleports to <body> and animates open/close, which happy-dom renders
// unreliably. The enrollment dialog's content is what these tests read, so stub the Dialog
// family to render its slot inline when open — the behaviour under test is the view's, not
// the portal's.
const DialogStub = defineComponent({
  name: 'Dialog',
  props: { open: { type: Boolean, default: false } },
  template: '<div v-if="open"><slot /></div>',
})
const slotStub = (name: string) =>
  defineComponent({ name, template: '<div><slot /></div>' })

const OtpInputStub = defineComponent({
  name: 'OtpInput',
  props: ['modelValue'],
  emits: ['update:modelValue'],
  template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" data-testid="otp" />',
})

const ConfirmDialogStub = defineComponent({
  name: 'ConfirmDialog',
  props: { open: { type: Boolean, default: false }, title: String, description: String, confirmLabel: String },
  emits: ['update:open', 'confirm'],
  template: '<div v-if="open"><slot /></div>',
})

const stubs = {
  OtpInput: OtpInputStub,
  Dialog: DialogStub,
  DialogContent: slotStub('DialogContent'),
  DialogHeader: slotStub('DialogHeader'),
  DialogTitle: slotStub('DialogTitle'),
  DialogDescription: slotStub('DialogDescription'),
  DialogFooter: slotStub('DialogFooter'),
  ConfirmDialog: ConfirmDialogStub,
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'account', component: { template: '<div />' } },
      { path: '/sessions', name: 'sessions', component: { template: '<div />' } },
    ],
  })
}

describe('AccountView — two-factor enrollment', () => {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  let wrapper: any
  let auth: ReturnType<typeof useAuthStore>

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())

    auth = useAuthStore()
    auth.user = meFixture(false)

    const router = makeRouter()
    await router.push('/')

    wrapper = mount(AccountView, {
      global: { plugins: [router], stubs },
    })
  })

  afterEach(() => {
    wrapper?.unmount()
  })

  const button = (text: string) => wrapper.findAll('button').find((b: { text: () => string }) => b.text().includes(text))

  it('offers Einrichten when the factor is not enrolled, and Deaktivieren when it is', async () => {
    expect(button('Einrichten')).toBeTruthy()
    expect(button('Deaktivieren')).toBeFalsy()

    // After confirm, /me reports the factor live and the button flips.
    vi.mocked(authApi.me).mockResolvedValue(meFixture(true))
    auth.user = meFixture(true)
    await wrapper.vm.$nextTick()

    expect(button('Deaktivieren')).toBeTruthy()
    expect(button('Einrichten')).toBeFalsy()
  })

  it('displays the QR and the secret after enrolment starts', async () => {
    vi.mocked(authApi.enrolMfa).mockResolvedValue({
      secret: 'GEZDGNBVGY3TQOJQ',
      provisioningUri: 'otpauth://totp/bentley:someone?secret=GEZDGNBVGY3TQOJQ',
      qrDataUrl: 'data:image/svg+xml,%3Csvg%3E%3C/svg%3E',
    })

    await button('Einrichten')!.trigger('click')
    await flush()

    expect(authApi.enrolMfa).toHaveBeenCalledOnce()
    expect(wrapper.find('img').attributes('src')).toBe('data:image/svg+xml,%3Csvg%3E%3C/svg%3E')
    expect(wrapper.text()).toContain('GEZDGNBVGY3TQOJQ')
  })

  it('reveals the one-time recovery codes after the code is confirmed', async () => {
    vi.mocked(authApi.enrolMfa).mockResolvedValue({
      secret: 'GEZDGNBVGY3TQOJQ',
      provisioningUri: 'otpauth://totp/bentley:someone?secret=GEZDGNBVGY3TQOJQ',
      qrDataUrl: 'data:image/svg+xml,x',
    })
    vi.mocked(authApi.confirmMfa).mockResolvedValue({ recoveryCodes: ['AAAA-BBBB', 'CCCC-DDDD'] })
    vi.mocked(authApi.me).mockResolvedValue(meFixture(true))

    await button('Einrichten')!.trigger('click')
    await flush()

    await wrapper.find('[data-testid="otp"]').setValue('123456')
    await button('Bestätigen')!.trigger('click')
    await flush()

    expect(authApi.confirmMfa).toHaveBeenCalledWith('123456')
    expect(wrapper.text()).toContain('AAAA-BBBB')
    expect(wrapper.text()).toContain('CCCC-DDDD')
  })

  it('does not confirm before six digits are entered', async () => {
    vi.mocked(authApi.enrolMfa).mockResolvedValue({
      secret: 'GEZDGNBVGY3TQOJQ',
      provisioningUri: 'otpauth://x',
      qrDataUrl: 'data:image/svg+xml,x',
    })

    await button('Einrichten')!.trigger('click')
    await flush()

    await wrapper.find('[data-testid="otp"]').setValue('12')
    await button('Bestätigen')!.trigger('click')
    await flush()

    // submitConfirm short-circuits on a too-short code without calling the API.
    expect(authApi.confirmMfa).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Geben Sie den sechsstelligen Code')
  })
})