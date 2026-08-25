<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'

import { useUiStore } from '@/stores/ui'

/**
 * The global loading bar.
 *
 * It is the SPA's only "something is happening" signal that is not local to a screen. The API
 * client bumps `ui.pendingRequests` around every request, and this bar renders while that count
 * is above zero.
 *
 * Two details that are easy to get wrong:
 *
 *  - **Debounced appearance.** A request that resolves in 5 ms should not flash a bar; it reads
 *    as flicker. So the bar waits ~200 ms before it appears, but hides the instant the count
 *    reaches zero — a slow request that *does* show should disappear as soon as it can.
 *  - **Teleported to `<body>`.** It sits above every layout, so it shows for auth screens and
 *    the admin shell alike, and never inherits a parent's `overflow` or transform.
 */

const ui = useUiStore()

const visible = ref(false)
let showTimer: ReturnType<typeof setTimeout> | undefined

watch(
  () => ui.pendingRequests,
  (count) => {
    if (count > 0) {
      // Only show if the request is still in flight after the grace period. A timer that fires
      // after the request already finished sets `visible` to true on a count that is now stale,
      // so re-check before flipping.
      showTimer = setTimeout(() => {
        if (ui.pendingRequests > 0) {
          visible.value = true
        }
      }, 200)
    } else {
      clearTimeout(showTimer)
      visible.value = false
    }
  },
)

onUnmounted(() => clearTimeout(showTimer))
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="animate-in fade-in duration-150"
      leave-active-class="animate-out fade-out duration-150"
    >
      <!-- z-100 matches the toast region: the bar is a 2px strip at the very top edge and never
           visually competes with anything, so sitting at the top of the stack is correct. -->
      <div
        v-if="visible"
        class="fixed inset-x-0 top-0 z-100 h-0.5 overflow-hidden bg-primary/15"
        role="progressbar"
        aria-busy="true"
        aria-label="Inhalt wird geladen"
      >
        <div class="h-full w-1/4 bg-primary [animation:loading-bar_1s_ease-in-out_infinite]" />
      </div>
    </Transition>
  </Teleport>
</template>