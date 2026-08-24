<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { computed } from 'vue'

import { Button } from '@/components/ui/button'

const props = defineProps<{ page: number; perPage: number; total: number; loading?: boolean }>()
const emit = defineEmits<{ 'update:page': [page: number] }>()

const lastPage = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
const from = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1))
const to = computed(() => Math.min(props.page * props.perPage, props.total))
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-2 px-1 py-3 text-sm">
    <p class="text-muted-foreground" aria-live="polite">
      {{ from }}–{{ to }} von {{ props.total }}
    </p>

    <div class="flex items-center gap-2">
      <Button
        variant="outline"
        size="sm"
        :disabled="props.page <= 1 || props.loading"
        aria-label="Vorherige Seite"
        @click="emit('update:page', props.page - 1)"
      >
        <ChevronLeft /> Zurück
      </Button>
      <span class="text-muted-foreground">Seite {{ props.page }} von {{ lastPage }}</span>
      <Button
        variant="outline"
        size="sm"
        :disabled="props.page >= lastPage || props.loading"
        aria-label="Nächste Seite"
        @click="emit('update:page', props.page + 1)"
      >
        Weiter <ChevronRight />
      </Button>
    </div>
  </div>
</template>
