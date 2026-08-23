<script setup lang="ts">
import { DropdownMenuItem, type DropdownMenuItemProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<DropdownMenuItemProps & { class?: HTMLAttributes['class'] }>()
</script>

<template>
  <DropdownMenuItem
    v-bind="props"
    :class="
      cn(
        'relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden',
        'data-highlighted:bg-accent data-highlighted:text-accent-foreground',
        'data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:size-4',
        props.class,
      )
    "
  >
    <slot />
  </DropdownMenuItem>
</template>
