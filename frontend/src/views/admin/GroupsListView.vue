<script setup lang="ts">
import { MoreHorizontal, Pencil, Plus, ShieldCheck, Trash2, UsersRound } from 'lucide-vue-next'
import { onMounted, ref } from 'vue'

import {
  createGroup,
  deleteGroup,
  listGroupMembers,
  listGroups,
  setGroupMembers,
  setGroupRoles,
  updateGroup,
  type AdminGroup,
} from '@/api/admin/groups'
import { listRoles, type AdminRole } from '@/api/admin/roles'
import { listUsers, type AdminUser } from '@/api/admin/users'
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
import { useToast } from '@/composables/useToast'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { usePermission } from '@/composables/usePermission'

const { busy, run } = useAsyncAction()
const toast = useToast()

const canCreate = usePermission('group.create')
const canUpdate = usePermission('group.update')
const canDelete = usePermission('group.delete')

const { items, loading, error, load } = usePaginatedResource<AdminGroup>(() => listGroups())

/** Both pickers degrade to empty rather than failing the page when the caller lacks the read. */
const roles = ref<AdminRole[]>([])
const users = ref<AdminUser[]>([])

onMounted(async () => {
  await load()

  try {
    roles.value = (await listRoles()).items
  } catch {
    roles.value = []
  }

  try {
    users.value = (await listUsers({ perPage: 100 })).items
  } catch {
    users.value = []
  }
})

const columns: Column[] = [
  { key: 'name', label: 'Gruppe' },
  { key: 'description', label: 'Beschreibung' },
  { key: 'roles', label: 'Getragene Rollen' },
  { key: 'memberCount', label: 'Mitglieder' },
  { key: 'actions', label: '', class: 'w-12 text-right' },
]

const createOpen = ref(false)
const form = ref({ name: '', description: '' })

async function submitCreate(): Promise<void> {
  const created = await run(
    () => createGroup(form.value.name.trim(), form.value.description.trim() || null),
    `${form.value.name.trim()} wurde angelegt.`,
  )

  if (created.ok) {
    createOpen.value = false
    form.value = { name: '', description: '' }
    await load()
  }
}

const editing = ref<AdminGroup | null>(null)
const editForm = ref({ name: '', description: '' })

function startEdit(group: AdminGroup): void {
  editing.value = group
  editForm.value = { name: group.name, description: group.description ?? '' }
}

async function submitEdit(): Promise<void> {
  const group = editing.value
  if (!group) return

  const saved = await run(
    () => updateGroup(group.id, editForm.value.name.trim(), editForm.value.description.trim() || null),
    'Die Gruppe wurde gespeichert.',
  )

  if (saved.ok) {
    editing.value = null
    await load()
  }
}

const editingRoles = ref<AdminGroup | null>(null)
const selectedRoles = ref<string[]>([])

function startRoles(group: AdminGroup): void {
  editingRoles.value = group
  selectedRoles.value = [...group.roles]
}

function toggleRole(name: string): void {
  selectedRoles.value = selectedRoles.value.includes(name)
    ? selectedRoles.value.filter((r) => r !== name)
    : [...selectedRoles.value, name]
}

async function submitRoles(): Promise<void> {
  const group = editingRoles.value
  if (!group) return

  if ((await run(() => setGroupRoles(group.id, selectedRoles.value), `${group.name} wurde aktualisiert.`)).ok) {
    editingRoles.value = null
    await load()
  }
}

const editingMembers = ref<AdminGroup | null>(null)
const selectedMembers = ref<string[]>([])
const memberFilter = ref('')

const membersLoading = ref(false)

/**
 * Open the picker with the current membership already ticked.
 *
 * Loading it matters more than it looks: saving REPLACES the membership, so a dialog that
 * opened empty would quietly empty the group the moment someone pressed save.
 */
async function startMembers(group: AdminGroup): Promise<void> {
  editingMembers.value = group
  memberFilter.value = ''
  selectedMembers.value = []
  membersLoading.value = true

  try {
    const [everyone, current] = await Promise.all([
      listUsers({ perPage: 100 }),
      listGroupMembers(group.id),
    ])
    users.value = everyone.items
    selectedMembers.value = current.items.map((member) => member.id)
  } catch (error) {
    // Never leave the dialog open with an empty selection it might save over the top of.
    editingMembers.value = null
    toast.fromError(error, 'Die aktuellen Mitglieder konnten nicht geladen werden.')
  } finally {
    membersLoading.value = false
  }
}

function toggleMember(id: string): void {
  selectedMembers.value = selectedMembers.value.includes(id)
    ? selectedMembers.value.filter((m) => m !== id)
    : [...selectedMembers.value, id]
}

async function submitMembers(): Promise<void> {
  const group = editingMembers.value
  if (!group) return

  const saved = await run(
    () => setGroupMembers(group.id, selectedMembers.value),
    `Die Mitglieder von ${group.name} wurden gespeichert.`,
  )

  if (saved.ok) {
    editingMembers.value = null
    await load()
  }
}

const deleting = ref<AdminGroup | null>(null)

async function confirmDelete(): Promise<void> {
  const group = deleting.value
  if (!group) return

  if ((await run(() => deleteGroup(group.id), `${group.name} wurde gelöscht.`)).ok) {
    deleting.value = null
    await load()
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Gruppen</h1>
        <p class="text-sm text-muted-foreground">
          Eine Gruppe macht „die Leute in diesem Team“ zu einem einzigen Subjekt. Hier angehängte
          Rollen erbt jedes Mitglied — wer einer Gruppe hinzugefügt wird, erhält also alles, was
          sie trägt.
        </p>
      </div>
      <Button v-if="canCreate" @click="createOpen = true"><Plus /> Neue Gruppe</Button>
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error"
      empty-title="Keine Gruppen angelegt"
      empty-description="Mit Gruppen erteilen Sie mehreren Personen auf einmal Zugriff."
      @retry="load()"
    >
      <template #cell:name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
      </template>
      <template #cell:description="{ row }">
        <span class="text-muted-foreground">{{ row.description ?? '—' }}</span>
      </template>
      <template #cell:roles="{ row }">
        <span v-if="row.roles.length === 0" class="text-xs text-muted-foreground">Keine</span>
        <div v-else class="flex flex-wrap gap-1">
          <Badge v-for="role in row.roles" :key="role" variant="secondary">{{ role }}</Badge>
        </div>
      </template>
      <template #cell:memberCount="{ row }">{{ row.memberCount }}</template>

      <template #cell:actions="{ row }">
        <DropdownMenu v-if="canUpdate || canDelete">
          <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" :aria-label="`Aktionen für ${row.name}`">
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem v-if="canUpdate" @select="startEdit(row)">
              <Pencil /> Details bearbeiten
            </DropdownMenuItem>
            <DropdownMenuItem v-if="canUpdate" @select="startRoles(row)">
              <ShieldCheck /> Rollen…
            </DropdownMenuItem>
            <DropdownMenuItem v-if="canUpdate" @select="startMembers(row)">
              <UsersRound /> Mitglieder…
            </DropdownMenuItem>
            <template v-if="canDelete">
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
          <DialogTitle>Neue Gruppe</DialogTitle>
          <DialogDescription>Rollen und Mitglieder hängen Sie nach dem Anlegen an.</DialogDescription>
        </DialogHeader>
        <form id="create-group" class="space-y-3" @submit.prevent="submitCreate">
          <div class="space-y-1.5">
            <Label for="group-name">Name</Label>
            <Input id="group-name" v-model="form.name" placeholder="platform-team" />
            <p class="text-xs text-muted-foreground">Kleinbuchstaben, Ziffern und Bindestriche.</p>
          </div>
          <div class="space-y-1.5">
            <Label for="group-description">Beschreibung</Label>
            <Input id="group-description" v-model="form.description" placeholder="Optional" />
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="createOpen = false">Abbrechen</Button>
          <Button type="submit" form="create-group" :disabled="busy || form.name.trim() === ''">
            {{ busy ? 'Bitte warten…' : 'Gruppe anlegen' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="editing !== null" @update:open="(o: boolean) => !o && (editing = null)">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{{ editing?.name }} bearbeiten</DialogTitle>
          <DialogDescription>
            Ein Gruppenname darf korrigiert werden — anders als bei einem Rollennamen gleicht
            nichts im Code darauf ab.
          </DialogDescription>
        </DialogHeader>
        <form id="edit-group" class="space-y-3" @submit.prevent="submitEdit">
          <div class="space-y-1.5">
            <Label for="edit-group-name">Name</Label>
            <Input id="edit-group-name" v-model="editForm.name" />
          </div>
          <div class="space-y-1.5">
            <Label for="edit-group-description">Beschreibung</Label>
            <Input id="edit-group-description" v-model="editForm.description" />
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editing = null">Abbrechen</Button>
          <Button type="submit" form="edit-group" :disabled="busy">
            {{ busy ? 'Bitte warten…' : 'Speichern' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog :open="editingRoles !== null" @update:open="(o: boolean) => !o && (editingRoles = null)">
      <DialogContent class="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Rollen von {{ editingRoles?.name }}</DialogTitle>
          <DialogDescription>
            Jedes Mitglied erbt diese Rollen. Sie können nur eine Rolle anhängen, deren
            Berechtigungen Sie selbst besitzen.
          </DialogDescription>
        </DialogHeader>
        <div class="max-h-80 space-y-1 overflow-y-auto">
          <label
            v-for="role in roles"
            :key="role.id"
            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent"
          >
            <input
              type="checkbox"
              class="size-4"
              :checked="selectedRoles.includes(role.name)"
              @change="toggleRole(role.name)"
            />
            <code>{{ role.name }}</code>
          </label>
          <p v-if="roles.length === 0" class="text-sm text-muted-foreground">
            Es konnten keine Rollen geladen werden. Dafür wird die Berechtigung
            <code>role.read</code> benötigt.
          </p>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editingRoles = null">Abbrechen</Button>
          <Button :disabled="busy" @click="submitRoles">
            {{ busy ? 'Bitte warten…' : 'Rollen speichern' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog
      :open="editingMembers !== null"
      @update:open="(o: boolean) => !o && (editingMembers = null)"
    >
      <DialogContent class="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Mitglieder von {{ editingMembers?.name }}</DialogTitle>
          <DialogDescription>
            Beim Speichern wird die gesamte Mitgliedschaft ersetzt. Wer nicht angehakt ist, wird
            entfernt und verliert ab der nächsten Anfrage alles, was die Gruppe ihm erteilt hat.
          </DialogDescription>
        </DialogHeader>

        <Input
          v-model="memberFilter"
          placeholder="Nach Benutzername filtern"
          aria-label="Mitglieder filtern"
          :disabled="membersLoading"
        />

        <div class="max-h-80 space-y-1 overflow-y-auto">
          <label
            v-for="user in users.filter((u) => u.username.includes(memberFilter))"
            :key="user.id"
            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent"
          >
            <input
              type="checkbox"
              class="size-4"
              :checked="selectedMembers.includes(user.id)"
              @change="toggleMember(user.id)"
            />
            {{ user.username }}
          </label>
          <p v-if="users.length === 0" class="text-sm text-muted-foreground">
            Es konnten keine Benutzer geladen werden. Dafür wird die Berechtigung
            <code>user.read</code> benötigt.
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editingMembers = null">Abbrechen</Button>
          <Button :disabled="busy" @click="submitMembers">
            {{ busy ? 'Bitte warten…' : 'Mitglieder speichern' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="deleting !== null"
      :title="`${deleting?.name} löschen?`"
      description="Die Mitglieder verlieren sofort alles, was diese Gruppe ihnen erteilt hat. Das lässt sich nicht rückgängig machen."
      confirm-label="Gruppe löschen"
      :busy="busy"
      @update:open="(o: boolean) => !o && (deleting = null)"
      @confirm="confirmDelete"
    />
  </div>
</template>
