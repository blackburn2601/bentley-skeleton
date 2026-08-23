<script setup lang="ts">
import { ref } from 'vue'

import AppForm from '@/components/AppForm.vue'
import FormField from '@/components/FormField.vue'
import { requestPasswordReset } from '@/api/auth'
import { ApiError } from '@/api/problem'

const email = ref('')
const busy = ref(false)
const error = ref<ApiError | Error | null>(null)
const success = ref<string | null>(null)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const result = await requestPasswordReset(email.value)
    // Identical whether or not the address exists — this endpoint needs no credentials, so
    // any difference makes it a membership oracle for anyone who cares to try.
    success.value = result.message
  } catch (caught) {
    error.value = caught as ApiError
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppForm
    title="Reset your password"
    submit-label="Send a reset link"
    :busy="busy"
    :error="error"
    :success="success"
    @submit="submit"
  >
    <FormField id="email" v-model="email" label="Email" type="email" autocomplete="username" />

    <template #footer>
      <p class="flex flex-wrap justify-between gap-3 text-sm">
        <RouterLink :to="{ name: 'sign-in' }">Back to sign in</RouterLink>
      </p>
    </template>
  </AppForm>
</template>
