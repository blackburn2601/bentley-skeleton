<script setup lang="ts">
import { onMounted, ref } from 'vue'

import { listSessions, type Session } from '@/api/auth'
import { ApiError } from '@/api/problem'

const sessions = ref<Session[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    sessions.value = (await listSessions()).sessions
  } catch (caught) {
    error.value =
      caught instanceof ApiError ? caught.message : 'Ihre Sitzungen konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
})

function when(iso: string): string {
  return new Date(iso).toLocaleString('de-DE')
}

/** A readable device name. The user agent is untrusted text, so it is displayed, never parsed for meaning. */
function device(userAgent: string | null): string {
  if (!userAgent) return 'Unbekanntes Gerät'
  const match = /(Firefox|Chrome|Safari|Edge)\/[\d.]+/.exec(userAgent)
  return match ? match[1] : 'Unbekannter Browser'
}
</script>

<template>
  <section class="space-y-4">
    <h1>Aktive Sitzungen</h1>
    <p>
      Jede Zeile ist eine Anmeldung. Wenn Sie eine davon nicht wiedererkennen, melden Sie sich
      überall ab und ändern Sie Ihr Passwort.
    </p>

    <p v-if="loading" role="status">Wird geladen…</p>
    <p v-else-if="error" class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">{{ error }}</p>

    <table v-else class="w-full border-collapse text-sm">
      <thead>
        <tr>
          <th scope="col">Gerät</th>
          <th scope="col">Angemeldet</th>
          <th scope="col">IP-Adresse</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="session in sessions" :key="session.id">
          <td>
            {{ device(session.userAgent) }}
            <span v-if="session.current" class="inline-flex items-center rounded-md border border-border px-2 py-0.5 text-xs font-medium">Dieses Gerät</span>
          </td>
          <td>{{ when(session.createdAt) }}</td>
          <td><code>{{ session.ipAddress ?? 'unbekannt' }}</code></td>
        </tr>
        <tr v-if="sessions.length === 0">
          <td colspan="3">Keine aktiven Sitzungen.</td>
        </tr>
      </tbody>
    </table>

    <p><RouterLink :to="{ name: 'account' }">Zurück zu Ihrem Konto</RouterLink></p>
  </section>
</template>
