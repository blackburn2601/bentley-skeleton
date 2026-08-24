<script setup lang="ts">
import { computed, onMounted } from 'vue'

import { listPermissions, type AdminPermission } from '@/api/admin/permissions'
import ErrorState from '@/components/data/ErrorState.vue'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { usePaginatedResource } from '@/composables/usePaginatedResource'

const { items, loading, error, load } = usePaginatedResource<AdminPermission>(() =>
  listPermissions(),
)

onMounted(() => void load())

/** Grouped by resource, because "what can be done to a user?" is the question people ask. */
const byResource = computed(() => {
  const groups = new Map<string, AdminPermission[]>()

  for (const permission of items.value) {
    const existing = groups.get(permission.resource) ?? []
    existing.push(permission)
    groups.set(permission.resource, existing)
  }

  return [...groups.entries()].map(([resource, permissions]) => ({ resource, permissions }))
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">Berechtigungen</h1>
      <p class="text-sm text-muted-foreground">
        Im Code deklariert und in die Datenbank synchronisiert — dadurch sind Erteilungen in Git
        diffbar und überstehen ein erneutes Deployment. Diese Liste stammt aus den
        Datenbankzeilen: Weicht sie vom Katalog im Code ab, wurde der Sync-Befehl nicht
        ausgeführt.
      </p>
    </div>

    <ErrorState v-if="error" :error="error" @retry="load()" />

    <div v-else-if="loading" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <Skeleton v-for="n in 6" :key="n" class="h-32 w-full" />
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <Card v-for="group in byResource" :key="group.resource">
        <CardHeader>
          <CardTitle class="capitalize">{{ group.resource }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-wrap gap-1">
          <Badge v-for="permission in group.permissions" :key="permission.id" variant="outline">
            {{ permission.action }}
          </Badge>
        </CardContent>
      </Card>
    </div>
  </div>
</template>
