<script setup lang="ts">
import { onMounted } from 'vue'

import GlobalLoader from '@/components/app/GlobalLoader.vue'
import ToastRegion from '@/components/app/ToastRegion.vue'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'

/**
 * The application root: a layout outlet, the global loading bar and the toast region, nothing
 * else.
 *
 * The chrome used to live here. It now lives in the layout routes, so a signed-out visitor
 * never downloads the admin shell and `meta` can be declared once per layout rather than once
 * per screen. The loading bar is the exception: it is global to every layout, so it stays here.
 */
const auth = useAuthStore()
const { initialise } = useTheme()

onMounted(() => {
  // index.html already put the class on <html> before first paint; this adopts that choice
  // into reactive state so the toggle starts from the right place.
  initialise()

  if (!auth.resolved) {
    void auth.load()
  }
})
</script>

<template>
  <RouterView />
  <GlobalLoader />
  <ToastRegion />
</template>
