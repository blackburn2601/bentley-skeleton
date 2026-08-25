<script setup lang="ts">
import { Search } from 'lucide-vue-next'
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

const filters = ref<Record<string, string | undefined>>({ q: '' })

const { items, page, perPage, total, loading, error, load } =
  usePaginatedResource<AdminSecurityEvent>(
    ({ page, perPage }) => listAuditEvents({ page, perPage, q: filters.value.q || undefined }),
    filters,
  )

onMounted(() => void load())

const columns: Column[] = [
  { key: 'occurredAt', label: 'Zeitpunkt' },
  { key: 'type', label: 'Ereignis' },
  { key: 'actorId', label: 'Auslöser' },
  { key: 'ipAddress', label: 'IP' },
  { key: 'requestId', label: 'Anfrage' },
]

const formatDateTime = (iso: string): string => new Date(iso).toLocaleString('de-DE')
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">Audit-Protokoll</h1>
      <p class="text-sm text-muted-foreground">
        Ausschließlich anhängend, erzwungen über die Datenbankrechte statt über Konvention — die
        Anwendungsrolle hält INSERT und SELECT, nicht UPDATE oder DELETE.
      </p>
    </div>

    <div class="relative max-w-sm flex-1">
      <Search class="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        v-model="filters.q"
        class="pl-8"
        placeholder="Nach Ereignistyp, IP, Anfrage oder Auslöser-ID suchen"
        aria-label="Audit-Protokoll durchsuchen"
      />
    </div>

    <DataTable
      :columns="columns"
      :rows="items"
      :loading="loading"
      :error="error"
      empty-title="Keine Ereignisse passen zu diesem Suchbegriff"
      empty-description="Gesucht wird in Ereignistyp, IP, Anfrage und Auslöser-ID — auch ein Teil genügt. Versuchen Sie einen anderen Suchbegriff."
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
        <span v-else class="text-xs text-muted-foreground">System</span>
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
