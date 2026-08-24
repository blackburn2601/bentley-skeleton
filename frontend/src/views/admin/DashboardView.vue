<script setup lang="ts">
import { computed } from 'vue'

import { navigation } from '@/navigation'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuthStore } from '@/stores/auth'
import { useNavigation } from '@/composables/useNavigation'

const auth = useAuthStore()
const sections = useNavigation()

/** Everything the admin area offers, minus what this caller may reach. */
const unavailable = computed(() => {
  const visible = new Set(sections.value.flatMap((section) => section.items.map((i) => i.to)))

  return navigation
    .flatMap((section) => section.items)
    .filter((item) => !visible.has(item.to))
})

const permissionCount = computed(() => auth.user?.permissions.length ?? 0)
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">Administration</h1>
      <p class="text-sm text-muted-foreground">
        Signed in as {{ auth.user?.username }} with {{ permissionCount }} permissions.
      </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <RouterLink
        v-for="item in sections.flatMap((s) => s.items).filter((i) => i.to !== '/admin')"
        :key="item.to"
        :to="item.to"
        class="rounded-xl transition-colors hover:bg-accent/50"
      >
        <Card class="h-full">
          <CardHeader>
            <component :is="item.icon" class="size-5 text-muted-foreground" />
            <CardTitle>{{ item.label }}</CardTitle>
            <CardDescription>Requires {{ item.permission ?? 'no permission' }}</CardDescription>
          </CardHeader>
        </Card>
      </RouterLink>
    </div>

    <!--
      Deliberately shown rather than hidden.

      Permissions are resolved server-side and take effect on the next request (ADR-0011), so
      an administrator can grant one of these and this list shrinks on reload — no sign-out.
      Naming what is missing is also how someone works out what to ask for.
    -->
    <Card v-if="unavailable.length > 0">
      <CardHeader>
        <CardTitle>Not available to you</CardTitle>
        <CardDescription>
          These areas exist but your account does not hold the permission they need. A grant
          takes effect on your next request — you will not need to sign in again.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <ul class="space-y-1 text-sm">
          <li v-for="item in unavailable" :key="item.to" class="flex items-center gap-2">
            <component :is="item.icon" class="size-4 text-muted-foreground" />
            <span>{{ item.label }}</span>
            <code class="text-xs text-muted-foreground">{{ item.permission }}</code>
          </li>
        </ul>
      </CardContent>
    </Card>
  </div>
</template>
