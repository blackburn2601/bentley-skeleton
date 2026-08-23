import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Chrome state: things about the shell rather than about the data.
 *
 * Only the sidebar's collapsed state so far. It is remembered because a user who collapses it
 * means it, and re-expanding on every navigation is the kind of small rudeness that makes an
 * admin tool feel unfinished.
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

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value

    try {
      localStorage.setItem(SIDEBAR_KEY, sidebarCollapsed.value ? '1' : '0')
    } catch {
      // Not remembering the preference is survivable; failing to toggle is not.
    }
  }

  return { sidebarCollapsed, sidebarOpen, toggleSidebar }
})
