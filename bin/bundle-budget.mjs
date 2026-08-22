#!/usr/bin/env node
/**
 * Fail the build when the production bundle outgrows its budget.
 *
 * Bundle size is a user-facing property that no other check measures: nothing else fails when
 * a convenience dependency adds 300 KB to every first visit. The budget is deliberately
 * generous — it is there to catch a step change, not to police kilobytes.
 *
 * Measured on the gzipped size, because that is what actually crosses the network.
 */
import { gzipSync } from 'node:zlib'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const BUDGETS = {
  js: 400 * 1024,
  css: 100 * 1024,
}

const distDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'frontend', 'dist')

function walk(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    return statSync(path).isDirectory() ? walk(path) : [path]
  })
}

let files
try {
  files = walk(distDir)
} catch {
  console.error(`No build output at ${distDir}. Run the production build first.`)
  process.exit(1)
}

const totals = { js: 0, css: 0 }

for (const file of files) {
  const ext = file.endsWith('.js') ? 'js' : file.endsWith('.css') ? 'css' : null
  if (ext === null) continue
  // Source maps ship for debuggability but are fetched only when devtools is open.
  if (file.endsWith('.map')) continue
  totals[ext] += gzipSync(readFileSync(file)).byteLength
}

let failed = false
for (const [ext, budget] of Object.entries(BUDGETS)) {
  const used = totals[ext]
  const ok = used <= budget
  failed ||= !ok
  console.log(
    `  ${ok ? '✓' : '✗'} ${ext.padEnd(4)} ${(used / 1024).toFixed(1).padStart(7)} KB gzipped  (budget ${(budget / 1024).toFixed(0)} KB)`,
  )
}

if (failed) {
  console.error('\nThe bundle exceeds its budget. Check what was added, or raise the budget deliberately.')
  process.exit(1)
}
console.log('\nBundle within budget.')
