<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { useTheme, type Theme } from '@/composables/useTheme'

const { theme, toggle } = useTheme()

/**
 * Display names for the stored theme.
 *
 * The values in `Theme` are storage keys — they are written to localStorage and read back by
 * the pre-paint script in index.html — so they are deliberately not translated at the source.
 * This maps them for the label only.
 */
const THEME_LABELS: Record<Theme, string> = {
  light: 'hell',
  dark: 'dunkel',
  system: 'System',
}
</script>

<template>
  <Button
    variant="ghost"
    size="icon"
    :aria-label="`Design wechseln (aktuell ${THEME_LABELS[theme]})`"
    @click="toggle"
  >
    <Sun class="hidden dark:block" />
    <Moon class="block dark:hidden" />
  </Button>
</template>
