<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppForm from '@/components/AppForm.vue'
import FormField from '@/components/FormField.vue'
import OtpInput from '@/components/OtpInput.vue'
import { ApiError } from '@/api/problem'
import { useAuthStore } from '@/stores/auth'

/**
 * The half-authenticated landing screen (ADR-0026).
 *
 * Reached only by a caller whose password checked out but who owes a second factor. The router
 * guard keeps everyone else out, so the view does not re-check the pending state: it just
 * collects a code and hands it to the store.
 */
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const mode = ref<'totp' | 'recovery'>('totp')
const totpCode = ref('')
const recoveryCode = ref('')
const busy = ref(false)
const error = ref<ApiError | Error | null>(null)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    if (mode.value === 'totp') {
      await auth.verifyMfa(totpCode.value)
    } else {
      await auth.verifyMfaRecovery(recoveryCode.value)
    }

    // The store has cleared the pending state and loaded the now-verified session. Honor the
    // original destination if the sign-in screen carried one, else land on the account page.
    const redirect = route.query.redirect
    await router.push(typeof redirect === 'string' ? redirect : { name: 'account' })
  } catch (caught) {
    error.value = caught as ApiError
    // A wrong code leaves the session pending: clear the entry so the user is not tempted to
    // re-submit the same digits, but stay on the screen.
    totpCode.value = ''
    recoveryCode.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppForm
    title="Zwei-Faktor-Authentifizierung"
    :submit-label="mode === 'totp' ? 'Code bestätigen' : 'Wiederherstellungscode verwenden'"
    :busy="busy"
    :error="error"
    @submit="submit"
  >
    <div v-if="mode === 'totp'" class="space-y-1.5">
      <label for="otp" class="text-sm font-medium">Authenticator-Code</label>
      <OtpInput id="otp" v-model="totpCode" />
    </div>

    <FormField
      v-else
      id="recovery-code"
      v-model="recoveryCode"
      label="Wiederherstellungscode"
      autocomplete="off"
      hint="Einer der einmaligen Codes, die bei der Einrichtung angezeigt wurden."
    />

    <template #footer>
      <button
        type="button"
        class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
        @click="mode = mode === 'totp' ? 'recovery' : 'totp'; error = null"
      >
        {{
          mode === 'totp'
            ? 'Stattdessen einen Wiederherstellungscode verwenden'
            : 'Stattdessen den Authenticator-Code verwenden'
        }}
      </button>
    </template>
  </AppForm>
</template>