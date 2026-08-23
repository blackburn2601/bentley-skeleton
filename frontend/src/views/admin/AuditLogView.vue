<script setup lang="ts">
import { onMounted, ref } from 'vue'

import {
  humaniseEventType,
  listAuditEvents,
  type AdminSecurityEvent,
} from '@/api/admin/auditEvents'
import DataTable, { type Column } from '@/components/data/DataTable.vue'
import DataTablePagination from '@/components/data/DataTablePagination.vue'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { usePaginatedResource } from '@/composables/usePaginatedResource'

const filters = ref<Record<string, string | undefined>>({ type: '' })

const { items, page, perPage, total, loading, error, load } =
  usePaginatedResource<AdminSecurityEvent>(
    ({ page, perPage }) => listAuditEvents({ page, perPage, type: filters.value.type || undefined }),
    filters,
  )

onMounted(() => void load())

const columns: Column[] = [
  { key: 'occurredAt', label: 'When' },
  { key: 'type', label: 'Event' },
  { key: 'actorId', label: 'Actor' },
  { key: 'ipAddress', label: 'IP' },
  { key: 'requestId', label: 'Request' },
]

const formatDateTime = (iso: string): string => new Date(iso).toLocaleString()
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">Audit log</h1>
      <p class="text-sm text-muted-foreground">
        Append-only, enforced by the database grant rather than by convention — the application
        role holds INSERT and SELECT, not UPDATE or DELETE.
      </p>
    </div>

    <Input
      v-model="filters.type"
      class="max-w-sm"
      placeholder="Filter by event type, e.g. login_failed"
      aria-label="Filter by event type"
    />

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error"
      empty-title="No events match this filter"
      empty-description="Event types are exact, like login_succeeded or permission_granted."
      @retry="load()"
    >
      <template #cell:occurredAt="{ row }">
        <span class="whitespace-nowrap text-muted-foreground">
          {{ formatDateTime(row.occurredAt) }}
        </span>
      </template>
      <template #cell:type="{ row }">
        <Badge :variant="row.highSeverity ? 'destructive' : 'outline'">
          {{ humaniseEventType(row.type) }}
        </Badge>
      </template>
      <template #cell:actorId="{ row }">
        <code v-if="row.actorId" class="text-xs text-muted-foreground">
          {{ row.actorId.slice(0, 8) }}
        </code>
        <span v-else class="text-xs text-muted-foreground">system</span>
      </template>
      <template #cell:ipAddress="{ row }">
        <span class="text-muted-foreground">{{ row.ipAddress ?? '—' }}</span>
      </template>
      <template #cell:requestId="{ row }">
        <code v-if="row.requestId" class="text-xs text-muted-foreground">{{ row.requestId }}</code>
        <span v-else class="text-muted-foreground">—</span>
      </template>
    </DataTable>

    <DataTablePagination
      v-model:page="page"
      :per-page="perPage"
      :total="total"
      :loading="loading"
    />
  </div>
</template>
