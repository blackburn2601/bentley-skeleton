<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import { eraseMyAccount, exportMyData } from '@/api/auth'
import { ApiError } from '@/api/problem'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const busy = ref<string | null>(null)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const confirmErase = ref('')

async function download(): Promise<void> {
  busy.value = 'export'
  error.value = null

  try {
    const data = await exportMyData()

    // Built and revoked here rather than linking to the endpoint: the export is a POST (it
    // writes an audit event), and a plain link would issue a GET.
    const url = URL.createObjectURL(
      new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }),
    )
    const link = document.createElement('a')
    link.href = url
    link.download = 'personal-data-export.json'
    link.click()
    URL.revokeObjectURL(url)

    notice.value = 'Your data has been downloaded.'
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'The export failed.'
  } finally {
    busy.value = null
  }
}

async function signOutEverywhere(): Promise<void> {
  busy.value = 'sessions'
  try {
    const revoked = await auth.signOutEverywhere()
    notice.value = `Ended ${revoked} session(s).`
    await router.push({ name: 'sign-in' })
  } finally {
    busy.value = null
  }
}

async function erase(): Promise<void> {
  busy.value = 'erase'
  error.value = null

  try {
    await eraseMyAccount()
    auth.onSessionLost()
    await router.push({ name: 'home' })
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'The request failed.'
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section class="space-y-4">
    <h1>Your account</h1>

    <p v-if="notice" class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">{{ error }}</p>

    <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
      <dt>Email</dt>
      <dd>{{ auth.user?.email }}</dd>
      <dt>Email confirmed</dt>
      <dd>{{ auth.user?.emailVerified ? 'Yes' : 'No' }}</dd>
      <dt>Two-factor</dt>
      <dd>{{ auth.user?.mfaEnabled ? 'Enabled' : 'Not enabled' }}</dd>
      <dt>Roles</dt>
      <dd>{{ auth.user?.roles.join(', ') || 'None' }}</dd>
    </dl>

    <h2>Sessions</h2>
    <p>
      <RouterLink :to="{ name: 'sessions' }">See where you are signed in</RouterLink>
    </p>
    <button type="button" :disabled="busy !== null" @click="signOutEverywhere">
      Sign out everywhere
    </button>

    <h2>Your data</h2>
    <button type="button" :disabled="busy !== null" @click="download">
      {{ busy === 'export' ? 'Preparing…' : 'Download a copy of my data' }}
    </button>

    <h2>Delete your account</h2>
    <p class="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
      This anonymises your account and ends every session. Security records are kept as
      required and no longer identify you. It cannot be undone.
    </p>
    <!--
      A typed confirmation rather than a confirm() dialog. Modal dialogs get dismissed by
      reflex; typing the word is a deliberate act, and it cannot be triggered by a stray
      Enter key on a focused button.
    -->
    <label for="confirm-erase">Type <code>DELETE</code> to confirm</label>
    <input id="confirm-erase" v-model="confirmErase" autocomplete="off" />
    <button
      type="button"
      class="inline-flex h-9 cursor-pointer items-center justify-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:pointer-events-none disabled:opacity-50"
      :disabled="confirmErase !== 'DELETE' || busy !== null"
      @click="erase"
    >
      Delete my account
    </button>
  </section>
</template>
