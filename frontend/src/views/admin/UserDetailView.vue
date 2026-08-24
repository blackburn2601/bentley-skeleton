<script setup lang="ts">
import { ArrowLeft, Copy, KeyRound, LogOut, Pencil, RotateCcw, ShieldCheck, ShieldOff, Trash2 } from 'lucide-vue-next'
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { adminResetMfa, adminSetMfaRequired } from '@/api/auth'
import { listRoles, type AdminRole } from '@/api/admin/roles'
import {
  assignRole,
  changeUserStatus,
  describeUser,
  eraseUser,
  resetUserPassword,
  revokeRole,
  revokeUserSessions,
  updateUser,
  USER_STATUSES,
  type AdminUserDetail,
  type UserStatus,
} from '@/api/admin/users'
import ConfirmDialog from '@/components/data/ConfirmDialog.vue'
import ErrorState from '@/components/data/ErrorState.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useAsyncAction } from '@/composables/useAsyncAction'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { usePermission } from '@/composables/usePermission'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { busy, run } = useAsyncAction()
const { copy } = useCopyToClipboard()

const canUpdate = usePermission('user.update')
const canDelete = usePermission('user.delete')
const canGrant = usePermission('permission.grant')
const canRevoke = usePermission('permission.revoke')

const id = String(route.params.id)
const user = ref<AdminUserDetail | null>(null)
const roles = ref<AdminRole[]>([])
const loading = ref(true)
const error = ref<unknown>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    user.value = await describeUser(id)
  } catch (caught) {
    error.value = caught
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()

  // The role picker is a convenience; a caller may hold user.read without role.read.
  try {
    roles.value = (await listRoles()).items
  } catch {
    roles.value = []
  }
})

const isSelf = (): boolean => user.value?.id === auth.user?.id

const copyId = (id: string): Promise<boolean> =>
  copy(id, 'ID kopiert.', 'Der Wert steht vollständig auf dieser Seite und lässt sich markieren.')

const editOpen = ref(false)
const usernameDraft = ref('')

function startEdit(): void {
  usernameDraft.value = user.value?.username ?? ''
  editOpen.value = true
}

async function submitEdit(): Promise<void> {
  const saved = await run(
    () => updateUser(id, usernameDraft.value.trim()),
    'Der Benutzername wurde geändert.',
  )

  if (saved.ok) {
    editOpen.value = false
    await load()
  }
}

async function setStatus(status: UserStatus): Promise<void> {
  // A whole sentence per outcome rather than a verb slotted into a template: German puts the
  // participle at the end, so an assembled `${verb} this account` cannot be made to read.
  const message =
    status === 'suspended' ? 'Dieses Konto wurde gesperrt.' : 'Dieses Konto wurde entsperrt.'

  if ((await run(() => changeUserStatus(id, status), message)).ok) {
    await load()
  }
}

async function toggleRole(role: string, held: boolean): Promise<void> {
  const action = held
    ? async () => void (await revokeRole(id, role))
    : async () => void (await assignRole(id, role))

  if ((await run(action, held ? `${role} wurde entzogen.` : `${role} wurde zugewiesen.`)).ok) {
    // Reload rather than patching locally: the effective permission list is computed by the
    // resolver, and roles inherited through a group would not follow a local edit.
    await load()
  }
}

async function endSessions(): Promise<void> {
  const result = await run(() => revokeUserSessions(id), 'Dieser Benutzer wurde überall abgemeldet.')

  if (result.ok) {
    await load()
  }
}

// --- MFA-Verwaltung (ADR-0026) ---------------------------------------------------------------

const mfaResetOpen = ref(false)

async function toggleMfaRequired(): Promise<void> {
  const next = !(user.value?.security.mfaRequired ?? false)
  const message = next
    ? 'MFA wurde für dieses Konto vorgeschrieben.'
    : 'Die MFA-Pflicht wurde für dieses Konto aufgehoben.'

  if ((await run(() => adminSetMfaRequired(id, next), message)).ok) {
    await load()
  }
}

async function confirmResetMfa(): Promise<void> {
  if ((await run(() => adminResetMfa(id), 'MFA wurde zurückgesetzt.')).ok) {
    mfaResetOpen.value = false
    await load()
  }
}

const eraseOpen = ref(false)

async function confirmErase(): Promise<void> {
  if ((await run(() => eraseUser(id), 'Das Konto wurde gelöscht.')).ok) {
    eraseOpen.value = false
    await router.push({ name: 'admin-users' })
  }
}

interface ResetResult {
  id: string
  username: string
  temporaryPassword: string
}

const resetOpen = ref(false)
const resetResult = ref<ResetResult | null>(null)
const copied = ref(false)

async function submitResetPassword(): Promise<void> {
  const result = await run(() => resetUserPassword(id), '')

  if (result.ok) {
    resetResult.value = result.value
    copied.value = false
    resetOpen.value = false
    await load()
  }
}

async function copyTemporaryPassword(): Promise<void> {
  if (!resetResult.value) return
  try {
    await navigator.clipboard.writeText(resetResult.value.temporaryPassword)
    copied.value = true
  } catch {
    copied.value = false
  }
}

const statusVariant: Record<UserStatus, 'success' | 'warning' | 'destructive' | 'secondary'> = {
  active: 'success',
  suspended: 'destructive',
  anonymised: 'secondary',
}

const statusLabel = (value: UserStatus): string =>
  USER_STATUSES.find((s) => s.value === value)?.label ?? value

const formatDateTime = (iso: string | null): string =>
  iso === null ? '—' : new Date(iso).toLocaleString('de-DE')
</script>

<template>
  <div class="space-y-6">
    <Button variant="ghost" size="sm" class="-ml-2" @click="router.push({ name: 'admin-users' })">
      <ArrowLeft /> Zurück zu den Benutzern
    </Button>

    <ErrorState v-if="error" :error="error" @retry="load()" />

    <div v-else-if="loading" class="space-y-4">
      <Skeleton class="h-9 w-72" />
      <Skeleton class="h-48 w-full" />
    </div>

    <template v-else-if="user">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">{{ user.username }}</h1>
          <div class="flex flex-wrap items-center gap-2 pt-1">
            <Badge :variant="statusVariant[user.status]">{{ statusLabel(user.status) }}</Badge>
            <span v-if="isSelf()" class="text-xs text-muted-foreground">Das ist Ihr eigenes Konto</span>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button v-if="canUpdate && !isSelf()" variant="outline" @click="startEdit">
            <Pencil /> Benutzername ändern
          </Button>
          <Button
            v-if="canUpdate && !isSelf()"
            variant="outline"
            :disabled="busy"
            @click="resetOpen = true"
          >
            <KeyRound /> Passwort zurücksetzen
          </Button>
          <Button v-if="canUpdate && !isSelf()" variant="outline" :disabled="busy" @click="endSessions">
            <LogOut /> Überall abmelden
          </Button>
          <Button
            v-if="canUpdate && !isSelf()"
            variant="outline"
            :disabled="busy"
            @click="toggleMfaRequired"
          >
            <component :is="user?.security.mfaRequired ? ShieldOff : ShieldCheck" />
            {{ user?.security.mfaRequired ? 'MFA nicht mehr erzwingen' : 'MFA erzwingen' }}
          </Button>
          <Button
            v-if="canUpdate && !isSelf()"
            variant="outline"
            :disabled="busy || !user?.security.mfaEnrolled"
            @click="mfaResetOpen = true"
          >
            <RotateCcw /> MFA zurücksetzen
          </Button>
          <Button
            v-if="canUpdate && !isSelf() && user.status !== 'suspended' && user.status !== 'anonymised'"
            variant="outline"
            :disabled="busy"
            @click="setStatus('suspended')"
          >
            <ShieldOff /> Sperren
          </Button>
          <Button
            v-if="canUpdate && !isSelf() && user.status === 'suspended'"
            variant="outline"
            :disabled="busy"
            @click="setStatus('active')"
          >
            <ShieldCheck /> Entsperren
          </Button>
          <Button
            v-if="canDelete && !isSelf() && user.status !== 'anonymised'"
            variant="destructive"
            @click="eraseOpen = true"
          >
            <Trash2 /> Löschen
          </Button>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Konto</CardTitle>
          </CardHeader>
          <CardContent>
            <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
              <!--
                In full, unlike the list, which abbreviates to fit a column. This is the page
                someone opens to quote the id into a ticket or a query, so the whole value has
                to be selectable by hand as well as copyable by button.
              -->
              <dt class="text-muted-foreground">ID</dt>
              <dd>
                <button
                  type="button"
                  class="group inline-flex cursor-pointer items-start gap-1.5 text-left hover:text-foreground"
                  :aria-label="`ID von ${user.username} kopieren`"
                  title="Zum Kopieren klicken"
                  @click="copyId(user.id)"
                >
                  <code class="break-words leading-5">{{ user.id }}</code>
                  <Copy class="mt-0.5 size-3.5 shrink-0 text-muted-foreground group-hover:text-foreground" />
                </button>
              </dd>
              <dt class="text-muted-foreground">Erstellt</dt>
              <dd>{{ formatDateTime(user.createdAt) }}</dd>
              <dt class="text-muted-foreground">Passwort geändert</dt>
              <dd>{{ formatDateTime(user.security.passwordChangedAt) }}</dd>
              <dt class="text-muted-foreground">Fehlgeschlagene Anmeldungen</dt>
              <dd>{{ user.security.failedLoginCount }}</dd>
              <dt class="text-muted-foreground">Gesperrt bis</dt>
              <dd>{{ formatDateTime(user.security.lockedUntil) }}</dd>
              <dt class="text-muted-foreground">MFA eingerichtet</dt>
              <dd>{{ user.security.mfaEnrolled ? 'Ja' : 'Nein' }}</dd>
              <dt class="text-muted-foreground">MFA vorgeschrieben</dt>
              <dd>{{ user.security.mfaRequired ? 'Ja' : 'Nein' }}</dd>
              <dt class="text-muted-foreground">ACL-Version</dt>
              <dd>
                {{ user.aclVersion }}
                <span class="text-xs text-muted-foreground">
                  — zählt bei jeder Änderung an Berechtigungen hoch; genau das lässt sie ab der
                  nächsten Anfrage wirken
                </span>
              </dd>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Gruppen</CardTitle>
            <CardDescription>
              Wird auf der Gruppenseite verwaltet. Rollen, die eine Gruppe trägt, erbt jedes ihrer
              Mitglieder.
            </CardDescription>
          </CardHeader>
          <CardContent class="flex flex-wrap gap-1">
            <Badge v-for="group in user.access.groups" :key="group" variant="secondary">{{ group }}</Badge>
            <span v-if="user.access.groups.length === 0" class="text-sm text-muted-foreground">
              In keiner Gruppe
            </span>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Rollen</CardTitle>
            <CardDescription>
              Direkt diesem Konto zugewiesen. Über eine Gruppe geerbte Rollen stehen nicht hier —
              sie tauchen in den effektiven Berechtigungen auf.
            </CardDescription>
          </CardHeader>
          <CardContent class="space-y-1">
            <label
              v-for="role in roles"
              :key="role.id"
              class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
              :class="canGrant || canRevoke ? 'cursor-pointer hover:bg-accent' : ''"
            >
              <input
                type="checkbox"
                class="size-4"
                :checked="user.access.roles.includes(role.name)"
                :disabled="busy || isSelf() || !(user.access.roles.includes(role.name) ? canRevoke : canGrant)"
                @change="toggleRole(role.name, user.access.roles.includes(role.name))"
              />
              <code>{{ role.name }}</code>
            </label>
            <p v-if="roles.length === 0" class="text-sm text-muted-foreground">
              Es konnten keine Rollen geladen werden. Dafür wird die Berechtigung
              <code>role.read</code> benötigt.
            </p>
            <p v-else-if="isSelf()" class="pt-1 text-xs text-muted-foreground">
              Sie können Ihre eigenen Rollen nicht ändern.
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Effektive Berechtigungen</CardTitle>
            <CardDescription>
              Was diese Person tatsächlich darf — direkte Rollen plus alles, was sie über ihre
              Gruppen erbt. Ermittelt von demselben Resolver, mit dem der Server autorisiert.
            </CardDescription>
          </CardHeader>
          <CardContent class="flex flex-wrap gap-1">
            <Badge
              v-for="permission in user.access.effectivePermissions"
              :key="permission"
              variant="outline"
            >
              {{ permission }}
            </Badge>
            <span v-if="user.access.effectivePermissions.length === 0" class="text-sm text-muted-foreground">
              Keine klassenweiten Berechtigungen
            </span>
          </CardContent>
        </Card>
      </div>
    </template>

    <Dialog v-model:open="editOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Benutzernamen ändern</DialogTitle>
          <DialogDescription>
            Der Benutzername ist die Identität des Kontos. Auf dem Server wird er ohne Rücksicht
            auf Groß- und Kleinschreibung verglichen.
          </DialogDescription>
        </DialogHeader>
        <form id="edit-user" class="space-y-1.5" @submit.prevent="submitEdit">
          <Label for="user-username">Benutzername</Label>
          <Input id="user-username" v-model="usernameDraft" type="text" autocomplete="off" />
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editOpen = false">Abbrechen</Button>
          <Button type="submit" form="edit-user" :disabled="busy || usernameDraft.trim() === ''">
            {{ busy ? 'Bitte warten…' : 'Speichern' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!--
      Confirm before resetting: the reset generates a one-time password that cannot be
      retrieved again, so it should be a deliberate act.
    -->
    <Dialog :open="resetOpen" @update:open="(o: boolean) => !o && (resetOpen = false)">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Passwort für {{ user?.username }} zurücksetzen</DialogTitle>
          <DialogDescription>
            Dabei wird ein neues, einmaliges Übergangspasswort erzeugt. Das bisherige Passwort
            funktioniert sofort nicht mehr. Das Übergangspasswort wird danach genau einmal
            angezeigt — die Person sollte also bereitstehen, um es entgegenzunehmen.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="resetOpen = false">Abbrechen</Button>
          <Button variant="destructive" :disabled="busy" @click="submitResetPassword">
            {{ busy ? 'Bitte warten…' : 'Passwort zurücksetzen' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!--
      The one-time temporary password. The API returns it exactly once and never again; it is
      not persisted server-side. Closing this dialog without copying it means only another reset
      can recover it.
    -->
    <Dialog
      :open="resetResult !== null"
      @update:open="(o: boolean) => !o && (resetResult = null)"
    >
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Übergangspasswort für {{ resetResult?.username }}</DialogTitle>
          <DialogDescription>
            Wird nur dieses eine Mal angezeigt — geben Sie es jetzt weiter. Es lässt sich später
            nicht erneut abrufen.
          </DialogDescription>
        </DialogHeader>
        <div class="space-y-3">
          <div class="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-warning">
            Dieses Passwort wird nicht erneut angezeigt. Kopieren Sie es jetzt.
          </div>
          <code class="block break-all rounded-md border bg-muted px-3 py-2 text-sm">
            {{ resetResult?.temporaryPassword }}
          </code>
          <Button class="w-full" @click="copyTemporaryPassword">
            {{ copied ? 'Kopiert' : 'In die Zwischenablage kopieren' }}
          </Button>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="resetResult = null">Fertig</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      v-model:open="eraseOpen"
      :title="`${user?.username} löschen?`"
      description="Das Konto wird anonymisiert und jede seiner Sitzungen beendet. Das lässt sich nicht rückgängig machen — das Audit-Protokoll bleibt erhalten, die persönlichen Daten sind aber fort."
      confirm-label="Konto löschen"
      confirm-phrase="LÖSCHEN"
      :busy="busy"
      @confirm="confirmErase"
    />

    <!--
      Resetting strips the user's factor and clears any admin-enforced requirement, ending every
      session so the next login starts clean. A deliberate act: the user must re-enroll from
      their account screen.
    -->
    <ConfirmDialog
      v-model:open="mfaResetOpen"
      :title="`MFA für ${user?.username} zurücksetzen?`"
      description="Dabei wird der zweite Faktor entfernt und eine eventuell vorgeschriebene MFA-Pflicht aufgehoben. Alle Sitzungen dieser Person werden beendet."
      confirm-label="MFA zurücksetzen"
      :busy="busy"
      @confirm="confirmResetMfa"
    />
  </div>
</template>