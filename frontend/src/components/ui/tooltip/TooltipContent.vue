<script setup lang="ts">
import { TooltipContent, TooltipPortal, type TooltipContentProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = withDefaults(
  defineProps<TooltipContentProps & { class?: HTMLAttributes['class'] }>(),
  { sideOffset: 6 },
)
</script>

<template>
  <TooltipPortal>
    <TooltipContent
      v-bind="props"
      :class="
        cn(
          'z-50 overflow-hidden rounded-md bg-foreground px-2.5 py-1 text-xs text-background shadow-md',
          props.class,
        )
      "
    >
      <slot />
    </TooltipContent>
  </TooltipPortal>
</template>
