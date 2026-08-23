<script setup lang="ts">
import { ArrowLeft, LogOut, Pencil, ShieldCheck, ShieldOff, Trash2 } from 'lucide-vue-next'
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { listRoles, type AdminRole } from '@/api/admin/roles'
import {
  assignRole,
  changeUserStatus,
  describeUser,
  eraseUser,
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
import { usePermission } from '@/composables/usePermission'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { busy, run } = useAsyncAction()

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

const editOpen = ref(false)
const emailDraft = ref('')

function startEdit(): void {
  emailDraft.value = user.value?.email ?? ''
  editOpen.value = true
}

async function submitEdit(): Promise<void> {
  const saved = await run(
    () => updateUser(id, emailDraft.value.trim()),
    'Email changed. The new address must be verified before it can receive a password reset.',
  )

  if (saved.ok) {
    editOpen.value = false
    await load()
  }
}

async function setStatus(status: UserStatus): Promise<void> {
  const verb = status === 'suspended' ? 'Suspended' : 'Reinstated'
  if ((await run(() => changeUserStatus(id, status), `${verb} this account.`)).ok) {
    await load()
  }
}

async function toggleRole(role: string, held: boolean): Promise<void> {
  const action = held
    ? async () => void (await revokeRole(id, role))
    : async () => void (await assignRole(id, role))

  if ((await run(action, held ? `Revoked ${role}.` : `Assigned ${role}.`)).ok) {
    // Reload rather than patching locally: the effective permission list is computed by the
    // resolver, and roles inherited through a group would not follow a local edit.
    await load()
  }
}

async function endSessions(): Promise<void> {
  const result = await run(() => revokeUserSessions(id), 'Signed this user out everywhere.')

  if (result.ok) {
    await load()
  }
}

const eraseOpen = ref(false)

async function confirmErase(): Promise<void> {
  if ((await run(() => eraseUser(id), 'Account erased.')).ok) {
    eraseOpen.value = false
    await router.push({ name: 'admin-users' })
  }
}

const statusVariant: Record<UserStatus, 'success' | 'warning' | 'destructive' | 'secondary'> = {
  active: 'success',
  pending_verification: 'warning',
  suspended: 'destructive',
  anonymised: 'secondary',
}

const statusLabel = (value: UserStatus): string =>
  USER_STATUSES.find((s) => s.value === value)?.label ?? value

const formatDateTime = (iso: string | null): string =>
  iso === null ? '—' : new Date(iso).toLocaleString()
</script>

<template>
  <div class="space-y-6">
    <Button variant="ghost" size="sm" class="-ml-2" @click="router.push({ name: 'admin-users' })">
      <ArrowLeft /> Back to users
    </Button>

    <ErrorState v-if="error" :error="error" @retry="load()" />

    <div v-else-if="loading" class="space-y-4">
      <Skeleton class="h-9 w-72" />
      <Skeleton class="h-48 w-full" />
    </div>

    <template v-else-if="user">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">{{ user.email }}</h1>
          <div class="flex flex-wrap items-center gap-2 pt-1">
            <Badge :variant="statusVariant[user.status]">{{ statusLabel(user.status) }}</Badge>
            <Badge :variant="user.emailVerified ? 'outline' : 'warning'">
              {{ user.emailVerified ? 'Email verified' : 'Email unverified' }}
            </Badge>
            <Badge :variant="user.mfaEnabled ? 'outline' : 'secondary'">
              MFA {{ user.mfaEnabled ? 'on' : 'off' }}
            </Badge>
            <span v-if="isSelf()" class="text-xs text-muted-foreground">This is your account</span>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Button v-if="canUpdate && !isSelf()" variant="outline" @click="startEdit">
            <Pencil /> Edit email
          </Button>
          <Button v-if="canUpdate && !isSelf()" variant="outline" :disabled="busy" @click="endSessions">
            <LogOut /> Sign out everywhere
          </Button>
          <Button
            v-if="canUpdate && !isSelf() && user.status !== 'suspended' && user.status !== 'anonymised'"
            variant="outline"
            :disabled="busy"
            @click="setStatus('suspended')"
          >
            <ShieldOff /> Suspend
          </Button>
          <Button
            v-if="canUpdate && !isSelf() && user.status === 'suspended'"
            variant="outline"
            :disabled="busy"
            @click="setStatus('active')"
          >
            <ShieldCheck /> Reinstate
          </Button>
          <Button
            v-if="canDelete && !isSelf() && user.status !== 'anonymised'"
            variant="destructive"
            @click="eraseOpen = true"
          >
            <Trash2 /> Erase
          </Button>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Account</CardTitle>
          </CardHeader>
          <CardContent>
            <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
              <dt class="text-muted-foreground">Created</dt>
              <dd>{{ formatDateTime(user.createdAt) }}</dd>
              <dt class="text-muted-foreground">Password changed</dt>
              <dd>{{ formatDateTime(user.security.passwordChangedAt) }}</dd>
              <dt class="text-muted-foreground">Failed logins</dt>
              <dd>{{ user.security.failedLoginCount }}</dd>
              <dt class="text-muted-foreground">Locked until</dt>
              <dd>{{ formatDateTime(user.security.lockedUntil) }}</dd>
              <dt class="text-muted-foreground">ACL version</dt>
              <dd>
                {{ user.aclVersion }}
                <span class="text-xs text-muted-foreground">
                  — increments on every grant change, which is what makes it take effect on
                  their next request
                </span>
              </dd>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Groups</CardTitle>
            <CardDescription>
              Managed from the group screen. Roles carried by a group are inherited by everyone
              in it.
            </CardDescription>
          </CardHeader>
          <CardContent class="flex flex-wrap gap-1">
            <Badge v-for="group in user.access.groups" :key="group" variant="secondary">{{ group }}</Badge>
            <span v-if="user.access.groups.length === 0" class="text-sm text-muted-foreground">
              Not in any group
            </span>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Roles</CardTitle>
            <CardDescription>
              Assigned directly to this account. Roles inherited through a group are not listed
              here — they appear in the effective permissions.
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
              No roles could be loaded. This needs the <code>role.read</code> permission.
            </p>
            <p v-else-if="isSelf()" class="pt-1 text-xs text-muted-foreground">
              You cannot change your own roles.
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Effective permissions</CardTitle>
            <CardDescription>
              What this person can actually do — direct roles plus everything inherited through
              their groups. Computed by the same resolver the server authorizes with.
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
              No class-level permissions
            </span>
          </CardContent>
        </Card>
      </div>
    </template>

    <Dialog v-model:open="editOpen">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Change email address</DialogTitle>
          <DialogDescription>
            The new address starts unverified, and a verification email is sent to it. Password
            resets go to the new address from now on.
          </DialogDescription>
        </DialogHeader>
        <form id="edit-user" class="space-y-1.5" @submit.prevent="submitEdit">
          <Label for="user-email">Email address</Label>
          <Input id="user-email" v-model="emailDraft" type="email" autocomplete="off" />
        </form>
        <DialogFooter>
          <Button variant="outline" :disabled="busy" @click="editOpen = false">Cancel</Button>
          <Button type="submit" form="edit-user" :disabled="busy || emailDraft.trim() === ''">
            {{ busy ? 'Working…' : 'Save' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      v-model:open="eraseOpen"
      :title="`Erase ${user?.email}?`"
      description="This anonymises the account and ends every session it has. It cannot be undone — the audit trail is kept, but the person's details are gone."
      confirm-label="Erase account"
      confirm-phrase="ERASE"
      :busy="busy"
      @confirm="confirmErase"
    />
  </div>
</template>
