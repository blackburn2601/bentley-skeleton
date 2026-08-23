<script setup lang="ts">
import { KeyRound, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

import { listPermissions, type AdminPermission } from '@/api/admin/permissions'
import {
  createRole,
  deleteRole,
  listRoles,
  setRolePermissions,
  updateRole,
  type AdminRole,
} from '@/api/admin/roles'
import ConfirmDialog from '@/components/data/ConfirmDialog.vue'
import DataTable, { type Column } from '@/components/data/DataTable.vue'
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
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { usePermission } from '@/composables/usePermission'

const { busy, run } = useAsyncAction()

const canCreate = usePermission('role.create')
const canUpdate = usePermission('role.update')
const canDelete = usePermission('role.delete')
const canGrant = usePermission('permission.grant')

const { items, loading, error, load } = usePaginatedResource<AdminRole>(() => listRoles())
const permissions = ref<AdminPermission[]>([])

onMounted(async () => {
  await load()

  // Best-effort: the permission picker needs the catalogue, but a caller may hold role.read
  // without permission.read, and the roles table is still useful without it.
  try {
    permissions.value = (await listPermissions()).items
  } catch {
    permissions.value = []
  }
})

const columns: Column[] = [
  { key: 'name', label: 'Role' },
  { key: 'description', label: 'Description' },
  { key: 'permissions', label: 'Permissions' },
  { key: 'actions', label: '', class: 'w-12 text-right' },
]

/** Baseline roles the server refuses to delete, mirrored here so the option is not offered. */
const BASELINE = ['ROLE_SUPER_ADMIN', 'ROLE_USER']
const isBaseline = (role: AdminRole): boolean => BASELINE.includes(role.name)
const isSuperAdmin = (role: AdminRole): boolean => role.name === 'ROLE_SUPER_ADMIN'

const createOpen = ref(false)
const newName = ref('')
const newDescription = ref('')

async function submitCreate(): Promise<void> {
  const created = await run(
    () => createRole(newName.value.trim(), newDescription.value.trim() || null),
    `Created ${newName.value.trim()}.`,
  )

  if (created.ok) {
    createOpen.value = false
    newName.value = ''
    newDescription.value = ''
    await load()
  }
}

const editing = ref<AdminRole | null>(null)
const editDescription = ref('')

function startEdit(role: AdminRole): void {
  editing.value = role
  editDescription.value = role.description ?? ''
}

async function submitEdit(): Promise<void> {
  const role = editing.value
  if (!role) return

  if ((await run(() => updateRole(role.id, editDescription.value.trim() || null), 'Description saved.')).ok) {
    editing.value = null
    await load()
  }
}

const granting = ref<AdminRole | null>(null)
const selected = ref<string[]>([])

function startGrant(role: AdminRole): void {
  granting.value = role
  selected.value = [...role.permissions]
}

function toggle(name: string): void {
  selected.value = selected.value.includes(name)
    ? selected.value.filter((p) => p !== name)
    : [...selected.value, name]
}

async function submitGrant(): Promise<void> {
  const role = granting.value
  if (!role) return

  if ((await run(() => setRolePermissions(role.id, selected.value), `Updated ${role.name}.`)).ok) {
    granting.value = null
    await load()
  }
}

const deleting = ref<AdminRole | null>(null)

async function confirmDelete(): Promise<void> {
  const role = deleting.value
  if (!role) return

  if ((await run(() => deleteRole(role.id), `Deleted ${role.name}.`)).ok) {
    deleting.value = null
    await load()
  }
}

/** Grouped by resource so the picker reads as "what can be done to X". */
const grouped = computed(() => {
  const groups = new Map<string, AdminPermission[]>()

  for (const permission of permissions.value) {
    groups.set(permission.resource, [...(groups.get(permission.resource) ?? []), permission])
  }

  return [...groups.entries()].map(([resource, list]) => ({ resource, list }))
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Roles</h1>
        <p class="text-sm text-muted-foreground">
          A role is a named bundle of permissions. Granting one to a user, or to a group, is the
          coarse layer of the access model — object-level entries refine it.
        </p>
      </div>
      <Button v-if="canCreate" @click="createOpen = true"><Plus /> New role</Button>
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error"
      empty-title="No roles defined"
      @retry="load()"
    >
      <template #cell:name="{ row }">
        <code class="font-medium">{{ row.name }}</code>
      </template>
      <template #cell:description="{ row }">
        <span class="text-muted-foreground">{{ row.description ?? '—' }}</span>
      </template>
      <template #cell:permissions="{ row }">
        <!-- ROLE_SUPER_ADMIN carries no rows on purpose: it short-circuits the resolver, so a
             permission list on it would imply a meaning it does not have. -->
        <span v-if="isSuperAdmin(row)" class="text-xs text-muted-foreground">
          Short-circuits every check
        </span>
        <span v-else-if="row.permissions.length === 0" class="text-xs text-muted-foreground">
          None attached
        </span>
        <div v-else class="flex flex-wrap gap-1">
          <Badge v-for="permission in row.permissions" :key="permission" variant="secondary">
            {{ permission }}
          </Badge>
        </div>
      </template>

      <template #cell:actions="{ row }">
        <DropdownMenu v-if="canUpdate || canGrant || canDelete">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" :aria-label="`Actions for ${row.name}`">
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem v-if="canUpdate" @select="startEdit(row)">
              <Pencil /> Edit description
            </DropdownMenuItem>
            <DropdownMenuItem v-if="canGrant && !isSuperAdmin(row)" @select="startGrant(row)">
              <KeyRound /> Permissions…
            </DropdownMenuItem>
            <template v-if="canDelete && !isBaseline(row)">
              <DropdownMenuSeparator />
              <DropdownMenuItem
                class="text-destructive data-highlighted:text-destructive"
                @select="deleting = row"
              >
                <Trash2 /> Delete…
              </DropdownMenuItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenu>
      </template>
    </DataTable>

    <Dialog v-model:open="createOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>New role</DialogTitle>
          <DialogDescription>
            The role starts empty. Attach permissions afterwards — you can only grant what you
            hold yourself.
          </DialogDescription>
        </DialogHeader>
        <form id="create-role" class="space-y-3" @submit.prevent="submitCreate">
          <div class="space-y-1.5">
            <Label for="role-name">Name</Label>
            <Input id="role-name" v-model="newName" placeholder="ROLE_SUPPORT_LEAD" />
            <p class="text-xs text-muted-foreground">
              Uppercase, starting with <code>ROLE_</code>. Names cannot be changed later — they
              appear in every access token.
            </p>
          </div>
          <div class="space-y-1.5">
            <Label for="role-description">Description</Label>
            <Input id="role-description" v-model="newDescription" placeholder="Optional" />
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="createOpen = false">Cancel</Button>
          <Button type="submit" form="create-role" :disabled="busy || newName.trim() === ''">
            {{ busy ? 'Working…' : 'Create role' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="editing !== null" @update:open="(o: boolean) => !o && (editing = null)">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Edit {{ editing?.name }}</DialogTitle>
          <DialogDescription>Only the description can change.</DialogDescription>
        </DialogHeader>
        <form id="edit-role" class="space-y-1.5" @submit.prevent="submitEdit">
          <Label for="edit-role-description">Description</Label>
          <Input id="edit-role-description" v-model="editDescription" />
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editing = null">Cancel</Button>
          <Button type="submit" form="edit-role" :disabled="busy">
            {{ busy ? 'Working…' : 'Save' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="granting !== null" @update:open="(o: boolean) => !o && (granting = null)">
      <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Permissions for {{ granting?.name }}</DialogTitle>
          <DialogDescription>
            Everyone holding this role gains everything ticked here. You can only grant a
            permission you hold yourself — the server refuses the rest.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <div v-for="group in grouped" :key="group.resource">
            <p class="pb-1 text-xs font-medium uppercase tracking-wider text-muted-foreground">
              {{ group.resource }}
            </p>
            <div class="grid gap-1 sm:grid-cols-2">
              <label
                v-for="permission in group.list"
                :key="permission.id"
                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent"
              >
                <input
                  type="checkbox"
                  class="size-4"
                  :checked="selected.includes(permission.name)"
                  @change="toggle(permission.name)"
                />
                <code>{{ permission.name }}</code>
              </label>
            </div>
          </div>
          <p v-if="grouped.length === 0" class="text-sm text-muted-foreground">
            The permission catalogue could not be loaded. It needs the
            <code>permission.read</code> permission.
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="granting = null">Cancel</Button>
          <Button :disabled="busy" @click="submitGrant">
            {{ busy ? 'Working…' : 'Save permissions' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="deleting !== null"
      :title="`Delete ${deleting?.name}?`"
      description="Everyone holding this role loses whatever it granted them, immediately. This cannot be undone."
      confirm-label="Delete role"
      :busy="busy"
      @update:open="(o: boolean) => !o && (deleting = null)"
      @confirm="confirmDelete"
    />
  </div>
</template>
