<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppForm from '@/components/AppForm.vue'
import FormField from '@/components/FormField.vue'
import { ApiError } from '@/api/problem'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const busy = ref(false)
const error = ref<ApiError | Error | null>(null)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    await auth.signIn(username.value, password.value)

    // Back to wherever the guard interrupted, or the account page.
    const redirect = route.query.redirect
    await router.push(typeof redirect === 'string' ? redirect : { name: 'account' })
  } catch (caught) {
    error.value = caught as ApiError
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppForm title="Anmelden" submit-label="Anmelden" :busy="busy" :error="error" @submit="submit">
    <FormField
      id="username"
      v-model="username"
      label="Benutzername"
      type="text"
      autocomplete="username"
    />
    <FormField
      id="password"
      v-model="password"
      label="Passwort"
      type="password"
      autocomplete="current-password"
    />
  </AppForm>
</template>