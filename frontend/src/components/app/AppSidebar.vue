<script setup lang="ts">
import { X } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { useNavigation } from '@/composables/useNavigation'
import { cn } from '@/lib/utils'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const sections = useNavigation()

const ACTIVE = 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
</script>

<template>
  <!-- Overlay backdrop, small screens only. -->
  <div
    v-if="ui.sidebarOpen"
    class="fixed inset-0 z-30 bg-black/50 md:hidden"
    @click="ui.sidebarOpen = false"
  />

  <aside
    :class="
      cn(
        'fixed inset-y-0 left-0 z-40 flex flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width,transform] duration-200',
        ui.sidebarCollapsed ? 'w-16' : 'w-60',
        ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
      )
    "
  >
    <div class="flex h-14 shrink-0 items-center gap-2 px-4">
      <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
        <span class="grid size-7 shrink-0 place-items-center rounded-md bg-primary text-primary-foreground text-xs">b</span>
        <span v-if="!ui.sidebarCollapsed">bentley</span>
      </RouterLink>
      <Button
        variant="ghost"
        size="icon"
        class="ml-auto md:hidden"
        aria-label="Close navigation"
        @click="ui.sidebarOpen = false"
      >
        <X />
      </Button>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto px-2 py-4">
      <div v-for="section in sections" :key="section.label">
        <p
          v-if="!ui.sidebarCollapsed"
          class="px-3 pb-1 text-[0.7rem] font-medium uppercase tracking-wider text-muted-foreground"
        >
          {{ section.label }}
        </p>
        <ul class="space-y-0.5">
          <li v-for="item in section.items" :key="item.to">
            <RouterLink
              :to="item.to"
              :title="ui.sidebarCollapsed ? item.label : undefined"
              class="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
              :active-class="item.exact ? '' : ACTIVE"
              :exact-active-class="ACTIVE"
              @click="ui.sidebarOpen = false"
            >
              <component :is="item.icon" class="size-4 shrink-0" />
              <span v-if="!ui.sidebarCollapsed">{{ item.label }}</span>
            </RouterLink>
          </li>
        </ul>
      </div>
    </nav>
  </aside>
</template>
