<script setup lang="ts">
import AppThemeToggle from '@/components/app/AppThemeToggle.vue'
</script>

<template>
  <div class="grid min-h-dvh lg:grid-cols-2">
    <div class="flex flex-col">
      <header class="flex h-14 items-center justify-between px-4">
        <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
          <span class="grid size-7 place-items-center rounded-md bg-primary text-xs text-primary-foreground">b</span>
          bentley
        </RouterLink>
        <AppThemeToggle />
      </header>

      <main class="flex flex-1 items-center justify-center p-4">
        <div class="w-full max-w-sm">
          <RouterView />
        </div>
      </main>
    </div>

    <!-- Decorative half, hidden on small screens where it would just push the form down. -->
    <aside class="hidden bg-muted lg:flex lg:flex-col lg:justify-end lg:p-10">
      <blockquote class="space-y-2">
        <p class="text-lg leading-relaxed">
          A headless Symfony API with a per-object ACL, where the architecture is enforced by
          tooling rather than by convention.
        </p>
        <footer class="text-sm text-muted-foreground">bentley-skeleton</footer>
      </blockquote>
    </aside>
  </div>
</template>
