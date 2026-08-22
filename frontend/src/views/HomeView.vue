<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <section class="panel">
    <h1>bentley-skeleton</h1>
    <p>A headless Symfony API with a Vue SPA, built to be extended safely.</p>

    <p v-if="auth.isAuthenticated">
      Signed in as <strong>{{ auth.user?.email }}</strong> —
      <RouterLink :to="{ name: 'account' }">your account</RouterLink>
    </p>
    <p v-else>
      <RouterLink :to="{ name: 'sign-in' }">Sign in</RouterLink> or
      <RouterLink :to="{ name: 'register' }">create an account</RouterLink>.
    </p>
  </section>
</template>
