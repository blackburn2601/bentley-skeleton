<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <section class="space-y-4">
    <h1>bentley-skeleton</h1>
    <p>A headless Symfony API with a Vue SPA, built to be extended safely.</p>

    <p v-if="auth.isAuthenticated">
      Signed in as <strong>{{ auth.user?.username }}</strong> —
      <RouterLink :to="{ name: 'account' }">your account</RouterLink>
    </p>
    <p v-else>
      <RouterLink :to="{ name: 'sign-in' }">Sign in</RouterLink>.
    </p>
  </section>
</template>