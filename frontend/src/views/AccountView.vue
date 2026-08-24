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
    passwordError.value = 'Die neuen Passwörter stimmen nicht überein.'
    return
  }

  busy.value = 'password'
  try {
    await changePassword(currentPassword.value, newPassword.value)
    passwordNotice.value = 'Ihr Passwort wurde geändert. Diese Sitzung bleibt angemeldet.'
    currentPassword.value = ''
    newPassword.value = ''
    confirmNewPassword.value = ''
  } catch (caught) {
    passwordError.value =
      caught instanceof ApiError ? caught.message : 'Die Anfrage ist fehlgeschlagen.'
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

    notice.value = 'Ihre Daten wurden heruntergeladen.'
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Der Export ist fehlgeschlagen.'
  } finally {
    busy.value = null
  }
}

async function signOutEverywhere(): Promise<void> {
  busy.value = 'sessions'
  try {
    const revoked = await auth.signOutEverywhere()
    notice.value =
      revoked === 1 ? 'Eine Sitzung wurde beendet.' : `${revoked} Sitzungen wurden beendet.`
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
    await router.push({ name: 'sign-in' })
  } catch (caught) {
    error.value = caught instanceof ApiError ? caught.message : 'Die Anfrage ist fehlgeschlagen.'
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section class="space-y-4">
    <h1>Ihr Konto</h1>

    <p v-if="notice" class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success" role="status">{{ notice }}</p>
    <p v-if="error" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">{{ error }}</p>

    <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
      <dt>Benutzername</dt>
      <dd>{{ auth.user?.username }}</dd>
      <dt>Rollen</dt>
      <dd>{{ auth.user?.roles.join(', ') || 'Keine' }}</dd>
    </dl>

    <h2>Passwort ändern</h2>
    <form class="max-w-sm space-y-3" novalidate @submit.prevent="submitChangePassword">
      <p v-if="passwordNotice" class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success" role="status">
        {{ passwordNotice }}
      </p>
      <p v-if="passwordError" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
        {{ passwordError }}
      </p>
      <div class="space-y-1.5">
        <label for="current-password" class="text-sm font-medium">Aktuelles Passwort</label>
        <input
          id="current-password"
          v-model="currentPassword"
          type="password"
          autocomplete="current-password"
          class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </div>
      <div class="space-y-1.5">
        <label for="new-password" class="text-sm font-medium">Neues Passwort</label>
        <input
          id="new-password"
          v-model="newPassword"
          type="password"
          autocomplete="new-password"
          class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </div>
      <div class="space-y-1.5">
        <label for="confirm-new-password" class="text-sm font-medium">Neues Passwort bestätigen</label>
        <input
          id="confirm-new-password"
          v-model="confirmNewPassword"
          type="password"
          autocomplete="new-password"
          class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
          :aria-invalid="newPasswordMismatch ? 'true' : undefined"
        />
        <p v-if="newPasswordMismatch" class="text-xs text-destructive" role="alert">
          Die neuen Passwörter stimmen nicht überein.
        </p>
      </div>
      <button
        type="submit"
        class="inline-flex h-9 cursor-pointer items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
        :disabled="busy !== null || !canChangePassword"
      >
        {{ busy === 'password' ? 'Bitte warten…' : 'Passwort ändern' }}
      </button>
    </form>

    <h2>Sitzungen</h2>
    <p>
      <RouterLink :to="{ name: 'sessions' }">Sehen, wo Sie angemeldet sind</RouterLink>
    </p>
    <button type="button" :disabled="busy !== null" @click="signOutEverywhere">
      Überall abmelden
    </button>

    <h2>Ihre Daten</h2>
    <button type="button" :disabled="busy !== null" @click="download">
      {{ busy === 'export' ? 'Wird vorbereitet…' : 'Kopie meiner Daten herunterladen' }}
    </button>

    <h2>Konto löschen</h2>
    <p class="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
      Ihr Konto wird anonymisiert und jede Sitzung beendet. Sicherheitsrelevante Aufzeichnungen
      bleiben wie vorgeschrieben erhalten, lassen sich Ihnen aber nicht mehr zuordnen. Das lässt
      sich nicht rückgängig machen.
    </p>
    <!--
      A typed confirmation rather than a confirm() dialog. Modal dialogs get dismissed by
      reflex; typing the word is a deliberate act, and it cannot be triggered by a stray
      Enter key on a focused button.
    -->
    <label for="confirm-erase">Tippen Sie <code>LÖSCHEN</code> zur Bestätigung</label>
    <input id="confirm-erase" v-model="confirmErase" autocomplete="off" />
    <button
      type="button"
      class="inline-flex h-9 cursor-pointer items-center justify-center rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:pointer-events-none disabled:opacity-50"
      :disabled="confirmErase !== 'LÖSCHEN' || busy !== null"
      @click="erase"
    >
      Mein Konto löschen
    </button>
  </section>
</template>