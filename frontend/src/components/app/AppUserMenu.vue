<script setup lang="ts">
import { LogOut, User as UserIcon, MonitorSmartphone } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRouter } from 'vue-router'

import { Avatar } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const toast = useToast()

const username = computed(() => auth.user?.username ?? '')
const initials = computed(() => username.value.slice(0, 2).toUpperCase())

async function signOut(): Promise<void> {
  try {
    await auth.signOut()
  } catch {
    // signOut() clears local state in a finally, so the user is signed out either way.
    toast.info('Signed out locally', 'The server could not be reached.')
  }

  await router.push({ name: 'sign-in' })
}
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="gap-2 px-2" aria-label="Account menu">
        <Avatar>{{ initials }}</Avatar>
        <span class="hidden max-w-40 truncate text-sm sm:block">{{ username }}</span>
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent class="w-56">
      <DropdownMenuLabel class="truncate font-normal text-muted-foreground">
        {{ username }}
      </DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem as-child>
        <RouterLink to="/account"><UserIcon /> Your account</RouterLink>
      </DropdownMenuItem>
      <DropdownMenuItem as-child>
        <RouterLink to="/account/sessions"><MonitorSmartphone /> Active sessions</RouterLink>
      </DropdownMenuItem>
      <DropdownMenuSeparator />
      <!-- Exactly "Sign out": e2e/sign-in.spec.ts matches this label with { exact: true } to
           tell it apart from "Sign out everywhere" on the account page. -->
      <DropdownMenuItem @select="signOut"><LogOut /> Sign out</DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
