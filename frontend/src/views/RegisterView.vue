<script setup lang="ts">
import { computed, ref } from 'vue'

import AppForm from '@/components/AppForm.vue'
import FormField from '@/components/FormField.vue'
import { register } from '@/api/auth'
import { ApiError } from '@/api/problem'

const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref<ApiError | Error | null>(null)
const success = ref<string | null>(null)

const fieldErrors = computed(() =>
  error.value instanceof ApiError ? error.value.fieldErrors : {},
)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  success.value = null

  try {
    const result = await register(email.value, password.value)
    // The API answers identically whether or not the address was already registered, so this
    // message is deliberately non-committal — saying "check your inbox" for a known address
    // and something else for an unknown one would rebuild the enumeration oracle the server
    // works to avoid.
    success.value = result.message
    email.value = ''
    password.value = ''
  } catch (caught) {
    error.value = caught as ApiError
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppForm
    title="Create an account"
    submit-label="Create account"
    :busy="busy"
    :error="error"
    :success="success"
    @submit="submit"
  >
    <FormField
      id="email"
      v-model="email"
      label="Email"
      type="email"
      autocomplete="username"
      :error="fieldErrors.email"
    />
    <FormField
      id="password"
      v-model="password"
      label="Password"
      type="password"
      autocomplete="new-password"
      hint="At least 12 characters. Longer is better than more complicated."
      :error="fieldErrors.password"
    />

    <template #footer>
      <p class="form__links">
        <RouterLink :to="{ name: 'sign-in' }">Already have an account?</RouterLink>
      </p>
    </template>
  </AppForm>
</template>
