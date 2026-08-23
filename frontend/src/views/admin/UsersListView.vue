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
import { usePermission } from '@/composables/usePermission'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { busy, run } = useAsyncAction()

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
const newEmail = ref('')

async function submitCreate(): Promise<void> {
  const created = await run(
    () => createUser(newEmail.value.trim()),
    `Invited ${newEmail.value.trim()}. They have been emailed a link to set a password.`,
  )

  if (created.ok) {
    createOpen.value = false
    newEmail.value = ''
    await load()
  }
}

async function setStatus(user: AdminUser, status: UserStatus): Promise<void> {
  const verb = status === 'suspended' ? 'Suspended' : 'Reinstated'
  if ((await run(() => changeUserStatus(user.id, status), `${verb} ${user.email}.`)).ok) {
    await load()
  }
}

const eraseTarget = ref<AdminUser | null>(null)

async function confirmErase(): Promise<void> {
  const target = eraseTarget.value
  if (!target) return

  if ((await run(() => eraseUser(target.id), `Erased ${target.email}.`)).ok) {
    eraseTarget.value = null
    await load()
  }
}

const columns = computed<Column[]>(() => [
  { key: 'email', label: 'Email' },
  { key: 'status', label: 'Status' },
  { key: 'verified', label: 'Verified' },
  { key: 'mfa', label: 'MFA' },
  { key: 'createdAt', label: 'Created', class: 'text-muted-foreground' },
  { key: 'actions', label: '', class: 'w-12 text-right' },
])

const statusVariant: Record<UserStatus, 'success' | 'warning' | 'destructive' | 'secondary'> = {
  active: 'success',
  pending_verification: 'warning',
  suspended: 'destructive',
  anonymised: 'secondary',
}

const statusLabel = (value: UserStatus): string =>
  USER_STATUSES.find((s) => s.value === value)?.label ?? value

const formatDate = (iso: string): string => new Date(iso).toLocaleDateString()

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
        <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
        <p class="text-sm text-muted-foreground">
          Every account you are permitted to read. The list is filtered by the same rules as a
          direct lookup, so what is missing here would also be refused there.
        </p>
      </div>
      <Button v-if="canCreate" @click="createOpen = true"><Plus /> New user</Button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <div class="relative min-w-56 flex-1 sm:max-w-sm">
        <Search class="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          v-model="filters.q"
          class="pl-8"
          placeholder="Search by email"
          aria-label="Search users by email"
        />
      </div>

      <select
        v-model="filters.status"
        aria-label="Filter by status"
        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
      >
        <option :value="undefined">All statuses</option>
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
      empty-title="No users match this filter"
      empty-description="Try a different search term, or clear the status filter."
      @retry="load()"
    >
      <template #cell:email="{ row }">
        <RouterLink
          :to="{ name: 'admin-user', params: { id: row.id } }"
          class="font-medium hover:underline"
        >
          {{ row.email }}
        </RouterLink>
        <span v-if="isSelf(row)" class="ml-2 text-xs text-muted-foreground">(you)</span>
      </template>
      <template #cell:status="{ row }">
        <Badge :variant="statusVariant[row.status]">{{ statusLabel(row.status) }}</Badge>
      </template>
      <template #cell:verified="{ row }">
        <Badge :variant="row.emailVerified ? 'outline' : 'warning'">
          {{ row.emailVerified ? 'Yes' : 'No' }}
        </Badge>
      </template>
      <template #cell:mfa="{ row }">
        <Badge :variant="row.mfaEnabled ? 'outline' : 'secondary'">
          {{ row.mfaEnabled ? 'On' : 'Off' }}
        </Badge>
      </template>
      <template #cell:createdAt="{ row }">{{ formatDate(row.createdAt) }}</template>

      <template #cell:actions="{ row }">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" :aria-label="`Actions for ${row.email}`">
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem as-child>
              <RouterLink :to="{ name: 'admin-user', params: { id: row.id } }">
                <Pencil /> Open
              </RouterLink>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              v-if="canUpdate && actionable(row) && row.status !== 'suspended'"
              @select="setStatus(row, 'suspended')"
            >
              <ShieldOff /> Suspend
            </DropdownMenuItem>
            <DropdownMenuItem
              v-if="canUpdate && actionable(row) && row.status === 'suspended'"
              @select="setStatus(row, 'active')"
            >
              <ShieldCheck /> Reinstate
            </DropdownMenuItem>
            <template v-if="canDelete && actionable(row)">
              <DropdownMenuSeparator />
              <DropdownMenuItem
                class="text-destructive data-highlighted:text-destructive"
                @select="eraseTarget = row"
              >
                <Trash2 /> Erase…
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
          <DialogTitle>New user</DialogTitle>
          <DialogDescription>
            The account is created immediately. They choose their own password through a link
            we email — nobody here ever sees it.
          </DialogDescription>
        </DialogHeader>

        <form id="create-user" class="space-y-1.5" @submit.prevent="submitCreate">
          <Label for="new-user-email">Email address</Label>
          <Input
            id="new-user-email"
            v-model="newEmail"
            type="email"
            autocomplete="off"
            placeholder="person@example.com"
          />
        </form>

        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="createOpen = false">Cancel</Button>
          <Button type="submit" form="create-user" :disabled="busy || newEmail.trim() === ''">
            {{ busy ? 'Working…' : 'Create user' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="eraseTarget !== null"
      :title="`Erase ${eraseTarget?.email}?`"
      description="This anonymises the account and ends every session it has. It cannot be undone — the audit trail is kept, but the person's details are gone."
      confirm-label="Erase account"
      confirm-phrase="ERASE"
      :busy="busy"
      @update:open="(open: boolean) => !open && (eraseTarget = null)"
      @confirm="confirmErase"
    />
  </div>
</template>
