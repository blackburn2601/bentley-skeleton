<script setup lang="ts">
import { DropdownMenuContent, DropdownMenuPortal, type DropdownMenuContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<DropdownMenuContentProps & { class?: HTMLAttributes['class'] }>(),
  { sideOffset: 6, align: 'end' },
)
</script>

<template>
  <DropdownMenuPortal>
    <DropdownMenuContent
      v-bind="props"
      :class="
        cn(
          'z-50 min-w-[10rem] overflow-hidden rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md',
          'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
          props.class,
        )
      "
    >
      <slot />
    </DropdownMenuContent>
  </DropdownMenuPortal>
</template>
