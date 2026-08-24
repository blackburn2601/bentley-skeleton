<script setup lang="ts">
import { MoreHorizontal, Pencil, Plus, Search, ShieldOff, ShieldCheck, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

import {
  changeUserStatus,
  createUser,
  eraseUser,
  listUsers,
  USER_STATUSES,
  type AdminUser,
  type CreatedUser,
  type UserStatus,
} from '@/api/admin/users'
import ConfirmDialog from '@/components/data/ConfirmDialog.vue'
import DataTable, { type Column } from '@/components/data/DataTable.vue'
import DataTablePagination from '@/components/data/DataTablePagination.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useAsyncAction } from '@/composables/useAsyncAction'
import { useCopyToClipboard } from '@/composables/useCopyToClipboard'
import { usePermission } from '@/composables/usePermission'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { busy, run } = useAsyncAction()
const { copy } = useCopyToClipboard()

// Display only (INV-16). Every one of these endpoints re-checks server-side.
const canCreate = usePermission('user.create')
const canUpdate = usePermission('user.update')
const canDelete = usePermission('user.delete')

const filters = ref<Record<string, string | undefined>>({ q: '', status: undefined })

const { items, page, perPage, total, loading, error, load } = usePaginatedResource<AdminUser>(
  ({ page, perPage }) =>
    listUsers({
      page,
      perPage,
      q: filters.value.q || undefined,
      status: (filters.value.status as UserStatus | undefined) || undefined,
    }),
  filters,
)

onMounted(() => void load())

const createOpen = ref(false)
const newUsername = ref('')
const createdUser = ref<CreatedUser | null>(null)
const copied = ref(false)

async function submitCreate(): Promise<void> {
  const created = await run(() => createUser(newUsername.value.trim()), '')

  if (created.ok) {
    createOpen.value = false
    newUsername.value = ''
    createdUser.value = created.value
    copied.value = false
    await load()
  }
}

async function copyTemporaryPassword(): Promise<void> {
  if (!createdUser.value) return
  try {
    await navigator.clipboard.writeText(createdUser.value.temporaryPassword)
    copied.value = true
  } catch {
    // Clipboard may be unavailable (e.g. insecure context); leave the value visible for manual copy.
    copied.value = false
  }
}

async function setStatus(user: AdminUser, status: UserStatus): Promise<void> {
  // A whole sentence per outcome rather than a verb slotted into a template: German puts the
  // participle at the end, so an assembled `${verb} ${username}` cannot be made to read.
  const message =
    status === 'suspended'
      ? `${user.username} wurde gesperrt.`
      : `${user.username} wurde entsperrt.`

  if ((await run(() => changeUserStatus(user.id, status), message)).ok) {
    await load()
  }
}

const eraseTarget = ref<AdminUser | null>(null)

async function confirmErase(): Promise<void> {
  const target = eraseTarget.value
  if (!target) return

  if ((await run(() => eraseUser(target.id), `${target.username} wurde gelöscht.`)).ok) {
    eraseTarget.value = null
    await load()
  }
}

const columns = computed<Column[]>(() => [
  { key: 'id', label: 'ID', class: 'w-36' },
  { key: 'username', label: 'Benutzername' },
  { key: 'status', label: 'Status' },
  { key: 'createdAt', label: 'Erstellt', class: 'text-muted-foreground' },
  { key: 'actions', label: '', class: 'w-12 text-right' },
])

const statusVariant: Record<UserStatus, 'success' | 'warning' | 'destructive' | 'secondary'> = {
  active: 'success',
  suspended: 'destructive',
  anonymised: 'secondary',
}

const statusLabel = (value: UserStatus): string =>
  USER_STATUSES.find((s) => s.value === value)?.label ?? value

const formatDate = (iso: string): string => new Date(iso).toLocaleDateString('de-DE')

/**
 * The TAIL of the UUID, not the head.
 *
 * These ids are UUIDv7, whose leading bytes are a timestamp: accounts created in the same
 * moment share them. The demo fixtures all begin `01a0346c-0`, so a leading-8 abbreviation
 * rendered three identical-looking rows — precisely the thing this column exists to prevent.
 * The final group is the random part and differs per row.
 *
 * The full value is on the `title` and one click away in the clipboard. Rendering all 36
 * characters would squeeze every other column for an identifier read far less often than it
 * is copied.
 */
const shortId = (id: string): string => `…${id.slice(-12)}`

const copyId = (id: string): Promise<boolean> =>
  copy(id, 'ID kopiert.', 'Der vollständige Wert steht im Tooltip und auf der Detailseite.')

const isSelf = (user: AdminUser): boolean => user.id === auth.user?.id

/**
 * Whether the destructive actions are worth offering.
 *
 * The server refuses both cases anyway — you cannot suspend or erase yourself, and an
 * anonymised account is terminal — so this only avoids showing a menu item that is guaranteed
 * to fail. Opening the record stays available for everyone.
 */
const actionable = (user: AdminUser): boolean =>
  !isSelf(user) && user.status !== 'anonymised'
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Benutzer</h1>
        <p class="text-sm text-muted-foreground">
          Jedes Konto, das Sie lesen dürfen. Die Liste filtert nach denselben Regeln wie ein
          direkter Aufruf — was hier fehlt, würde dort ebenfalls abgelehnt.
        </p>
      </div>
      <Button v-if="canCreate" @click="createOpen = true"><Plus /> Neuer Benutzer</Button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <div class="relative min-w-56 flex-1 sm:max-w-sm">
        <Search class="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          v-model="filters.q"
          class="pl-8"
          placeholder="Nach Benutzername oder ID suchen"
          aria-label="Benutzer nach Benutzername oder ID durchsuchen"
        />
      </div>

      <select
        v-model="filters.status"
        aria-label="Nach Status filtern"
        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
      >
        <option :value="undefined">Alle Status</option>
        <option v-for="status in USER_STATUSES" :key="status.value" :value="status.value">
          {{ status.label }}
        </option>
      </select>
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error"
      empty-title="Keine Benutzer passen zu diesem Filter"
      empty-description="Gesucht wird in Benutzername und ID — auch ein Teil der ID genügt. Versuchen Sie einen anderen Suchbegriff oder setzen Sie den Statusfilter zurück."
      @retry="load()"
    >
      <template #cell:id="{ row }">
        <button
          type="button"
          class="cursor-pointer font-mono text-xs text-muted-foreground hover:text-foreground hover:underline"
          :title="`${row.id} — zum Kopieren klicken`"
          :aria-label="`ID von ${row.username} kopieren`"
          @click="copyId(row.id)"
        >
          {{ shortId(row.id) }}
        </button>
      </template>

      <template #cell:username="{ row }">
        <RouterLink
          :to="{ name: 'admin-user', params: { id: row.id } }"
          class="font-medium hover:underline"
        >
          {{ row.username }}
        </RouterLink>
        <span v-if="isSelf(row)" class="ml-2 text-xs text-muted-foreground">(Sie)</span>
      </template>
      <template #cell:status="{ row }">
        <Badge :variant="statusVariant[row.status]">{{ statusLabel(row.status) }}</Badge>
      </template>
      <template #cell:createdAt="{ row }">{{ formatDate(row.createdAt) }}</template>

      <template #cell:actions="{ row }">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" :aria-label="`Aktionen für ${row.username}`">
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem as-child>
              <RouterLink :to="{ name: 'admin-user', params: { id: row.id } }">
                <Pencil /> Öffnen
              </RouterLink>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              v-if="canUpdate && actionable(row) && row.status !== 'suspended'"
              @select="setStatus(row, 'suspended')"
            >
              <ShieldOff /> Sperren
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canUpdate && actionable(row) && row.status === 'suspended'"
              @select="setStatus(row, 'active')"
            >
              <ShieldCheck /> Entsperren
            </DropdownMenuItem>
            <template v-if="canDelete && actionable(row)">
              <DropdownMenuSeparator />
              <DropdownMenuItem
                class="text-destructive data-highlighted:text-destructive"
                @select="eraseTarget = row"
              >
                <Trash2 /> Löschen…
              </DropdownMenuItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenu>
      </template>
    </DataTable>

    <DataTablePagination
      v-model:page="page"
      :per-page="perPage"
      :total="total"
      :loading="loading"
    />

    <Dialog v-model:open="createOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Neuer Benutzer</DialogTitle>
          <DialogDescription>
            Das Konto wird sofort mit einem einmaligen Übergangspasswort angelegt. Es wird genau
            einmal angezeigt, direkt nach dem Anlegen — danach sieht es hier niemand mehr wieder.
            Geben Sie es der Person also jetzt weiter.
          </DialogDescription>
        </DialogHeader>

        <form id="create-user" class="space-y-1.5" @submit.prevent="submitCreate">
          <Label for="new-user-username">Benutzername</Label>
          <Input
            id="new-user-username"
            v-model="newUsername"
            type="text"
            autocomplete="off"
            placeholder="jdoe"
          />
        </form>

        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="createOpen = false">Abbrechen</Button>
          <Button type="submit" form="create-user" :disabled="busy || newUsername.trim() === ''">
            {{ busy ? 'Bitte warten…' : 'Benutzer anlegen' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!--
      The one-time temporary password. The API returns it exactly once and never again; it is
      not persisted server-side. Closing this dialog without copying it means only an admin
      reset can recover it.
    -->
    <Dialog :open="createdUser !== null" @update:open="(o: boolean) => !o && (createdUser = null)">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Übergangspasswort für {{ createdUser?.username }}</DialogTitle>
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
            {{ createdUser?.temporaryPassword }}
          </code>
          <Button class="w-full" @click="copyTemporaryPassword">
            {{ copied ? 'Kopiert' : 'In die Zwischenablage kopieren' }}
          </Button>
        </div>
        <DialogFooter>
          <Button variant="outline" @click="createdUser = null">Fertig</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="eraseTarget !== null"
      :title="`${eraseTarget?.username} löschen?`"
      description="Das Konto wird anonymisiert und jede seiner Sitzungen beendet. Das lässt sich nicht rückgängig machen — das Audit-Protokoll bleibt erhalten, die persönlichen Daten sind aber fort."
      confirm-label="Konto löschen"
      confirm-phrase="LÖSCHEN"
      :busy="busy"
      @update:open="(open: boolean) => !open && (eraseTarget = null)"
      @confirm="confirmErase"
    />
  </div>
</template>