<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next'
import { computed } from 'vue'

import { ApiError } from '@/api/problem'
import { Button } from '@/components/ui/button'

const props = defineProps<{ error: unknown }>()
defineEmits<{ retry: [] }>()

const message = computed(() =>
  props.error instanceof ApiError ? props.error.message : 'Etwas ist schiefgelaufen.',
)

/**
 * The server's request id, shown deliberately.
 *
 * "It failed" cannot be correlated with anything. This id is on the server's log line and, for
 * security-relevant actions, on the audit row — which is what turns a support message into a
 * query.
 */
const requestId = computed(() =>
  props.error instanceof ApiError ? props.error.requestId : undefined,
)
</script>

<template>
  <div class="flex flex-col items-center gap-2 px-6 py-12 text-center" role="alert">
    <TriangleAlert class="size-8 text-destructive" />
    <p class="font-medium">{{ message }}</p>
    <p v-if="requestId" class="text-xs text-muted-foreground">
      Referenz: <code>{{ requestId }}</code>
    </p>
    <Button variant="outline" size="sm" class="mt-2" @click="$emit('retry')">Erneut versuchen</Button>
  </div>
</template>
