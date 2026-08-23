<script setup lang="ts">
import { Menu, PanelLeft } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import AppThemeToggle from '@/components/app/AppThemeToggle.vue'
import AppUserMenu from '@/components/app/AppUserMenu.vue'
import { Button } from '@/components/ui/button'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const route = useRoute()

/** Breadcrumb trail from route meta, so a screen names itself in one place. */
const crumbs = computed<string[]>(() => {
  const trail = route.meta.breadcrumb
  if (Array.isArray(trail)) {
    return trail.filter((part): part is string => typeof part === 'string')
  }

  return typeof route.meta.title === 'string' ? [route.meta.title] : []
})
</script>

<template>
  <header
    class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b border-border bg-background/80 px-4 backdrop-blur"
  >
    <Button
      variant="ghost"
      size="icon"
      class="md:hidden"
      aria-label="Open navigation"
      @click="ui.sidebarOpen = true"
    >
      <Menu />
    </Button>
    <Button
      variant="ghost"
      size="icon"
      class="hidden md:inline-flex"
      aria-label="Toggle sidebar"
      @click="ui.toggleSidebar()"
    >
      <PanelLeft />
    </Button>

    <nav aria-label="Breadcrumb" class="min-w-0">
      <ol class="flex items-center gap-1.5 text-sm">
        <li v-for="(crumb, index) in crumbs" :key="crumb" class="flex items-center gap-1.5">
          <span v-if="index > 0" class="text-muted-foreground">/</span>
          <span :class="index === crumbs.length - 1 ? 'font-medium' : 'text-muted-foreground'">
            {{ crumb }}
          </span>
        </li>
      </ol>
    </nav>

    <div class="ml-auto flex items-center gap-1">
      <AppThemeToggle />
      <AppUserMenu />
    </div>
  </header>
</template>
