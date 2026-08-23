<script setup lang="ts" generic="T extends { id: string }">
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import EmptyState from '@/components/data/EmptyState.vue'
import ErrorState from '@/components/data/ErrorState.vue'

export interface Column {
  key: string
  label: string
  class?: string
}

const props = defineProps<{
  columns: Column[]
  rows: T[]
  loading: boolean
  error: unknown
  emptyTitle: string
  emptyDescription?: string
}>()

defineEmits<{ retry: [] }>()
defineSlots<{
  [key: `cell:${string}`]: (props: { row: T }) => unknown
}>()
</script>

<template>
  <div class="rounded-xl border border-border bg-card">
    <ErrorState v-if="props.error" :error="props.error" @retry="$emit('retry')" />

    <Table v-else>
      <TableHeader>
        <TableRow>
          <TableHead v-for="column in props.columns" :key="column.key" :class="column.class">
            {{ column.label }}
          </TableHead>
        </TableRow>
      </TableHeader>

      <TableBody>
        <!-- Skeleton rows rather than a spinner: the table does not change height when the
             data lands, so nothing the user was about to click moves. -->
        <template v-if="props.loading && props.rows.length === 0">
          <TableRow v-for="row in 5" :key="`skeleton-${row}`">
            <TableCell v-for="column in props.columns" :key="column.key">
              <Skeleton class="h-4 w-full" />
            </TableCell>
          </TableRow>
        </template>

        <TableRow v-else-if="props.rows.length === 0" class="hover:bg-transparent">
          <TableCell :colspan="props.columns.length" class="p-0">
            <EmptyState :title="props.emptyTitle" :description="props.emptyDescription" />
          </TableCell>
        </TableRow>

        <TableRow v-for="row in props.rows" v-else :key="row.id">
          <TableCell v-for="column in props.columns" :key="column.key" :class="column.class">
            <slot :name="`cell:${column.key}`" :row="row" />
          </TableCell>
        </TableRow>
      </TableBody>
    </Table>
  </div>
</template>
