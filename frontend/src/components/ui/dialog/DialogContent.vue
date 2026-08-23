<script setup lang="ts">
import {
  DialogContent,
  DialogOverlay,
  DialogPortal,
  type DialogContentProps,
  useForwardPropsEmits,
} from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<DialogContentProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<{
  escapeKeyDown: [event: KeyboardEvent]
  pointerDownOutside: [event: Event]
  focusOutside: [event: Event]
  interactOutside: [event: Event]
  openAutoFocus: [event: Event]
  closeAutoFocus: [event: Event]
}>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <DialogPortal>
    <DialogOverlay
      class="fixed inset-0 z-50 bg-black/50 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=closed]:animate-out data-[state=closed]:fade-out-0"
    />
    <DialogContent
      v-bind="forwarded"
      :class="
        cn(
          'fixed left-1/2 top-1/2 z-50 grid w-full max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4',
          'rounded-lg border border-border bg-background p-6 shadow-lg',
          'data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=closed]:animate-out data-[state=closed]:fade-out-0',
          props.class,
        )
      "
    >
      <slot />
    </DialogContent>
  </DialogPortal>
</template>
