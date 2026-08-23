<script setup lang="ts">
import AppSidebar from '@/components/app/AppSidebar.vue'
import AppTopbar from '@/components/app/AppTopbar.vue'
import { cn } from '@/lib/utils'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
</script>

<template>
  <div class="min-h-dvh bg-background">
    <AppSidebar />
    <div :class="cn('flex min-h-dvh flex-col transition-[padding]', ui.sidebarCollapsed ? 'md:pl-16' : 'md:pl-60')">
      <AppTopbar />
      <main class="flex-1 p-4 md:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
