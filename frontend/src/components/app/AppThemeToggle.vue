<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { useTheme } from '@/composables/useTheme'

const { theme, toggle } = useTheme()
</script>

<template>
  <Button variant="ghost" size="icon" :aria-label="`Switch theme (currently ${theme})`" @click="toggle">
    <Sun class="hidden dark:block" />
    <Moon class="block dark:hidden" />
  </Button>
</template>
