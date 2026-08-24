<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import { changePassword, eraseMyAccount, exportMyData } from '@/api/auth'
import { ApiError } from '@/api/problem'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const busy = ref<string | null>(null)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const confirmErase = ref('')

const currentPassword = ref('')
const newPassword = ref('')
const confirmNewPassword = ref('')
const passwordError = ref<string | null>(null)
const passwordNotice = ref<string | null>(null)

const newPasswordMismatch = computed(
  () => confirmNewPassword.value !== '' && newPassword.value !== confirmNewPassword.value,
)

const canChangePassword = computed(
  () =>
    currentPassword.value !== '' &&
    newPassword.value !== '' &&
    newPassword.value === confirmNewPassword.value,
)

async function submitChangePassword(): Promise<void> {
  passwordError.value = null
  passwordNotice.value = null

  if (newPassword.value !== confirmNewPassword.value) {
    passwordError.value = 'The new passwords do not match.'
    return
  }

  busy.value = 'password'
  try {
    await changePassword(currentPassword.value, newPassword.value)
    passwordNotice.value = 'Your password has been changed. This session stays signed in.'
    currentPassword.value = ''
    newPassword.value = ''
    confirmNewPassword.value = ''
  } catch (caught) {
    passwordError.value = caught instanceof ApiError ? caught.message : 'The request failed.'
  } finally {
    busy.value = null
  }
}

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
      <dt>Username</dt>
      <dd>{{ auth.user?.username }}</dd>
      <dt>Roles</dt>
      <dd>{{ auth.user?.roles.join(', ') || 'None' }}</dd>
    </dl>

    <h2>Change password</h2>
    <form class="max-w-sm space-y-3" novalidate @submit.prevent="submitChangePassword">
      <p v-if="passwordNotice" class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success" role="status">
        {{ passwordNotice }}
      </p>
      <p v-if="passwordError" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
        {{ passwordError }}
      </p>
      <div class="space-y-1.5">
        <label for="current-password" class="text-sm font-medium">Current password</label>
        <input
          id="current-password"
          v-model="currentPassword"
          type="password"
          autocomplete="current-password"
          class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </div>
      <div class="space-y-1.5">
        <label for="new-password" class="text-sm font-medium">New password</label>
        <input
          id="new-password"
          v-model="newPassword"
          type="password"
          autocomplete="new-password"
          class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </div>
      <div class="space-y-1.5">
        <label for="confirm-new-password" class="text-sm font-medium">Confirm new password</label>
        <input
          id="confirm-new-password"
          v-model="confirmNewPassword"
          type="password"
          autocomplete="new-password"
          class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
          :aria-invalid="newPasswordMismatch ? 'true' : undefined"
        />
        <p v-if="newPasswordMismatch" class="text-xs text-destructive" role="alert">
          The new passwords do not match.
        </p>
      </div>
      <button
        type="submit"
        class="inline-flex h-9 cursor-pointer items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        :disabled="busy !== null || !canChangePassword"
      >
        {{ busy === 'password' ? 'Working…' : 'Change password' }}
      </button>
    </form>

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