import { readonly, ref } from 'vue'

/**
 * Light, dark, or follow the operating system — remembered across reloads.
 *
 * The class on <html> is the single source of truth for CSS (`@custom-variant dark` in
 * style.css keys off it). It is deliberately NOT a `prefers-color-scheme` media query: a
 * media query cannot be overridden by a toggle, and having both means the OS and the user
 * fight over the same pixels with no defined winner.
 *
 * `index.html` applies the stored choice before first paint. Without that, every load of a
 * dark-themed page starts white and flashes — which looks broken and is worse on the eyes than
 * having no dark mode at all.
 */
export type Theme = 'light' | 'dark' | 'system'

export const THEME_STORAGE_KEY = 'bentley-theme'

const current = ref<Theme>('system')

function prefersDark(): boolean {
  return typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches
}

function isTheme(value: unknown): value is Theme {
  return value === 'light' || value === 'dark' || value === 'system'
}

/** Reading storage throws in some privacy modes, which must not stop the app from rendering. */
function readStored(): Theme {
  try {
    const stored = localStorage.getItem(THEME_STORAGE_KEY)
    return isTheme(stored) ? stored : 'system'
  } catch {
    return 'system'
  }
}

function apply(theme: Theme): void {
  const dark = theme === 'dark' || (theme === 'system' && prefersDark())
  document.documentElement.classList.toggle('dark', dark)
  document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
}

export function useTheme() {
  function set(theme: Theme): void {
    current.value = theme
    apply(theme)

    try {
      localStorage.setItem(THEME_STORAGE_KEY, theme)
    } catch {
      // A theme that cannot be remembered is a smaller problem than one that cannot be set.
    }
  }

  /** Called once at boot to adopt whatever index.html already applied. */
  function initialise(): void {
    current.value = readStored()
    apply(current.value)
  }

  function toggle(): void {
    set(document.documentElement.classList.contains('dark') ? 'light' : 'dark')
  }

  return { theme: readonly(current), set, toggle, initialise }
}
