<script setup lang="ts">
import { DialogTitle } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <DialogTitle :class="cn('text-lg font-semibold leading-none', props.class)"><slot /></DialogTitle>
</template>
