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
  { key: 'name', label: 'Rolle' },
  { key: 'description', label: 'Beschreibung' },
  { key: 'permissions', label: 'Berechtigungen' },
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
    `${newName.value.trim()} wurde angelegt.`,
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

  if ((await run(() => updateRole(role.id, editDescription.value.trim() || null), 'Die Beschreibung wurde gespeichert.')).ok) {
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

  if ((await run(() => setRolePermissions(role.id, selected.value), `${role.name} wurde aktualisiert.`)).ok) {
    granting.value = null
    await load()
  }
}

const deleting = ref<AdminRole | null>(null)

async function confirmDelete(): Promise<void> {
  const role = deleting.value
  if (!role) return

  if ((await run(() => deleteRole(role.id), `${role.name} wurde gelöscht.`)).ok) {
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
        <h1 class="text-2xl font-semibold tracking-tight">Rollen</h1>
        <p class="text-sm text-muted-foreground">
          Eine Rolle ist ein benanntes Bündel von Berechtigungen. Sie einem Benutzer oder einer
          Gruppe zu erteilen, ist die grobe Schicht des Zugriffsmodells — objektbezogene Einträge
          verfeinern sie.
        </p>
      </div>
      <Button v-if="canCreate" @click="createOpen = true"><Plus /> Neue Rolle</Button>
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error"
      empty-title="Keine Rollen angelegt"
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
          Übergeht jede Prüfung
        </span>
        <span v-else-if="row.permissions.length === 0" class="text-xs text-muted-foreground">
          Keine angehängt
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
            <Button variant="ghost" size="icon" :aria-label="`Aktionen für ${row.name}`">
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem v-if="canUpdate" @select="startEdit(row)">
              <Pencil /> Beschreibung bearbeiten
            </DropdownMenuItem>
            <DropdownMenuItem v-if="canGrant && !isSuperAdmin(row)" @select="startGrant(row)">
              <KeyRound /> Berechtigungen…
            </DropdownMenuItem>
            <template v-if="canDelete && !isBaseline(row)">
              <DropdownMenuSeparator />
              <DropdownMenuItem
                class="text-destructive data-highlighted:text-destructive"
                @select="deleting = row"
              >
                <Trash2 /> Löschen…
              </DropdownMenuItem>
            </template>
          </DropdownMenuContent>
        </DropdownMenu>
      </template>
    </DataTable>

    <Dialog v-model:open="createOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Neue Rolle</DialogTitle>
          <DialogDescription>
            Die Rolle beginnt leer. Berechtigungen hängen Sie danach an — erteilen können Sie nur,
            was Sie selbst besitzen.
          </DialogDescription>
        </DialogHeader>
        <form id="create-role" class="space-y-3" @submit.prevent="submitCreate">
          <div class="space-y-1.5">
            <Label for="role-name">Name</Label>
            <Input id="role-name" v-model="newName" placeholder="ROLE_SUPPORT_LEAD" />
            <p class="text-xs text-muted-foreground">
              Großbuchstaben, beginnend mit <code>ROLE_</code>. Namen lassen sich später nicht
              mehr ändern — sie stehen in jedem Access-Token.
            </p>
          </div>
          <div class="space-y-1.5">
            <Label for="role-description">Beschreibung</Label>
            <Input id="role-description" v-model="newDescription" placeholder="Optional" />
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="createOpen = false">Abbrechen</Button>
          <Button type="submit" form="create-role" :disabled="busy || newName.trim() === ''">
            {{ busy ? 'Bitte warten…' : 'Rolle anlegen' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="editing !== null" @update:open="(o: boolean) => !o && (editing = null)">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ editing?.name }} bearbeiten</DialogTitle>
          <DialogDescription>Nur die Beschreibung lässt sich ändern.</DialogDescription>
        </DialogHeader>
        <form id="edit-role" class="space-y-1.5" @submit.prevent="submitEdit">
          <Label for="edit-role-description">Beschreibung</Label>
          <Input id="edit-role-description" v-model="editDescription" />
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editing = null">Abbrechen</Button>
          <Button type="submit" form="edit-role" :disabled="busy">
            {{ busy ? 'Bitte warten…' : 'Speichern' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="granting !== null" @update:open="(o: boolean) => !o && (granting = null)">
      <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Berechtigungen von {{ granting?.name }}</DialogTitle>
          <DialogDescription>
            Wer diese Rolle besitzt, erhält alles, was hier angehakt ist. Sie können nur eine
            Berechtigung erteilen, die Sie selbst besitzen — alles andere weist der Server ab.
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
            Der Berechtigungskatalog konnte nicht geladen werden. Dafür wird die Berechtigung
            <code>permission.read</code> benötigt.
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="granting = null">Abbrechen</Button>
          <Button :disabled="busy" @click="submitGrant">
            {{ busy ? 'Bitte warten…' : 'Berechtigungen speichern' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="deleting !== null"
      :title="`${deleting?.name} löschen?`"
      description="Wer diese Rolle besitzt, verliert sofort alles, was sie ihm erteilt hat. Das lässt sich nicht rückgängig machen."
      confirm-label="Rolle löschen"
      :busy="busy"
      @update:open="(o: boolean) => !o && (deleting = null)"
      @confirm="confirmDelete"
    />
  </div>
</template>
