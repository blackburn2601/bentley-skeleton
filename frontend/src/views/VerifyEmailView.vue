<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import { verifyEmail } from '@/api/auth'
import { ApiError } from '@/api/problem'

const route = useRoute()

const state = ref<'working' | 'done' | 'failed'>('working')
const message = ref('')

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''

  if (!token) {
    state.value = 'failed'
    message.value = 'This link is missing its token.'
    return
  }

  try {
    // Verified with a POST from here rather than by the emailed link being a GET: mail
    // clients and security scanners prefetch links, and a GET that mutates state would mark
    // the address confirmed before the person ever clicked.
    const result = await verifyEmail(token)
    state.value = 'done'
    message.value = result.message
  } catch (caught) {
    state.value = 'failed'
    message.value = caught instanceof ApiError ? caught.message : 'Something went wrong.'
  }
})
</script>

<template>
  <section class="space-y-4">
    <h1>Confirm your email</h1>

    <p v-if="state === 'working'" role="status">Confirming…</p>
    <p v-else-if="state === 'done'" class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success" role="status">{{ message }}</p>
    <p v-else class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">{{ message }}</p>

    <p v-if="state === 'done'">
      <RouterLink :to="{ name: 'sign-in' }">Sign in</RouterLink>
    </p>
  </section>
</template>
