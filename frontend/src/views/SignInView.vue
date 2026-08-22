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

const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref<ApiError | Error | null>(null)

async function submit(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    await auth.signIn(email.value, password.value)

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
  <AppForm title="Sign in" submit-label="Sign in" :busy="busy" :error="error" @submit="submit">
    <FormField id="email" v-model="email" label="Email" type="email" autocomplete="username" />
    <FormField
      id="password"
      v-model="password"
      label="Password"
      type="password"
      autocomplete="current-password"
    />

    <template #footer>
      <p class="form__links">
        <RouterLink :to="{ name: 'forgot-password' }">Forgotten your password?</RouterLink>
        <RouterLink :to="{ name: 'register' }">Create an account</RouterLink>
      </p>
    </template>
  </AppForm>
</template>
