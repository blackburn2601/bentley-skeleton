import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Chrome state: things about the shell rather than about the data.
 *
 * The sidebar's collapsed state is remembered because a user who collapses it means it, and
 * re-expanding on every navigation is the kind of small rudeness that makes an admin tool feel
 * unfinished. The in-flight request counter drives the global loading bar: every request that
 * goes through the API client bumps it on the way in and drops it on the way out, and the bar
 * shows whenever it is above zero.
 */
const SIDEBAR_KEY = 'bentley-sidebar-collapsed'

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(SIDEBAR_KEY) === '1'
  } catch {
    return false
  }
}

export const useUiStore = defineStore('ui', () => {
  const sidebarCollapsed = ref(readCollapsed())
  /** Separate from `collapsed`: on small screens the sidebar is an overlay, not a rail. */
  const sidebarOpen = ref(false)

  /**
   * In-flight request count. The API client bumps this around every `fetch` (see
   * `setLoadingHandler` in `api/client.ts`); `GlobalLoader.vue` renders the bar while it is
   * above zero. Not persisted — it is ephemeral, and a remembered counter would be stale on
   * reload.
   */
  const pendingRequests = ref(0)

  function beginRequest(): void {
    pendingRequests.value++
  }

  function endRequest(): void {
    // `Math.max` guards against drift below zero if an end arrives without a matching start,
    // which a swallowed error in a finally path could otherwise make permanent.
    pendingRequests.value = Math.max(0, pendingRequests.value - 1)
  }

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value

    try {
      localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value ? '1' : '0')
    } catch {
      // Not remembering the preference is survivable; failing to toggle is not.
    }
  }

  return { sidebarCollapsed, sidebarOpen, pendingRequests, toggleSidebar, beginRequest, endRequest }
})
