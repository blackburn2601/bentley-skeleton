<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppForm from '@/components/AppForm.vue'
import FormField from '@/components/FormField.vue'
import { resetPassword } from '@/api/auth'
import { ApiError } from '@/api/problem'

const route = useRoute()
const router = useRouter()

const password = ref('')
const busy = ref(false)
const error = ref<ApiError | Error | null>(null)
const success = ref<string | null>(null)

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const fieldErrors = computed(() =>
  error.value instanceof ApiError ? error.value.fieldErrors : {},
)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const result = await resetPassword(token.value, password.value)
    success.value = result.message
    password.value = ''

    // Every session was revoked by the reset, so there is nothing to return to but sign-in.
    setTimeout(() => void router.push({ name: 'sign-in' }), 1500)
  } catch (caught) {
    error.value = caught as ApiError
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppForm
    title="Choose a new password"
    submit-label="Change password"
    :busy="busy"
    :error="error"
    :success="success"
    @submit="submit"
  >
    <p v-if="!token" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
      This link is missing its token. Request a new reset link.
    </p>

    <FormField
      id="password"
      v-model="password"
      label="New password"
      type="password"
      autocomplete="new-password"
      hint="At least 12 characters, and not one you use anywhere else."
      :error="fieldErrors.password"
    />

    <template #footer>
      <p class="flex flex-wrap justify-between gap-3 text-sm">
        <RouterLink :to="{ name: 'forgot-password' }">Request a new link</RouterLink>
      </p>
    </template>
  </AppForm>
</template>
