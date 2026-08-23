import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Merge Tailwind classes, letting later ones win.
 *
 * Plain string concatenation does not work with utility classes: `"p-2" + "p-4"` leaves both
 * in the class list and the winner is whichever CSS rule happens to come last, not the one the
 * caller passed last. `twMerge` resolves conflicts by utility group, so a component's default
 * padding can be overridden by a prop without `!important`.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs))
}
