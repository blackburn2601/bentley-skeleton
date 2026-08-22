<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

onMounted(() => {
  // The router guard already resolves the session before the first navigation; this only
  // covers the case where the app is mounted without one.
  if (!auth.resolved) {
    void auth.load()
  }
})

async function signOut(): Promise<void> {
  await auth.signOut()
  await router.push({ name: 'home' })
}
</script>

<template>
  <div class="app">
    <header class="app__header">
      <RouterLink :to="{ name: 'home' }" class="app__brand">bentley</RouterLink>

      <nav class="app__nav">
        <template v-if="auth.isAuthenticated">
          <RouterLink :to="{ name: 'account' }">{{ auth.user?.email }}</RouterLink>
          <button type="button" class="button--link" @click="signOut">Sign out</button>
        </template>
        <template v-else>
          <RouterLink :to="{ name: 'sign-in' }">Sign in</RouterLink>
        </template>
      </nav>
    </header>

    <main class="app__main">
      <RouterView />
    </main>
  </div>
</template>
