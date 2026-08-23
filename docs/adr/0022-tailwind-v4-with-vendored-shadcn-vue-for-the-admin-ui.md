# 0022. Tailwind v4 with hand-vendored shadcn-vue as the SPA design system

- **Status:** accepted
- **Date:** 2026-08-23

## Context

The SPA had no design system: one 236-line hand-written `style.css`, two components
(`AppForm`, `FormField`), and a `<main>` capped at 42rem. That was a deliberate choice, and
`style.css` says so in its opening comment:

> Deliberately small and unopinionated… whoever starts a project from it will replace the
> visual design entirely, and shipping a design system they have to unpick first is a cost,
> not a gift.

That reasoning holds for a skeleton with nine auth screens. It stops holding the moment the
product needs sortable tables, dialogs, dropdowns, multi-selects, toasts and a permission-aware
sidebar — roughly fifteen admin screens. Hand-writing an accessible combobox and dialog is not
"unopinionated", it is unfinished, and accessibility is the part that gets dropped.

Constraints that ruled out most of the field: TypeScript is pinned to `~6.0.2` by ADR-0018,
`tsconfig.app.json` sets `erasableSyntaxOnly` (no TS enums, no constructor parameter
properties), ESLint runs at `--max-warnings=0` with `vue/multi-word-component-names: error` and
`@typescript-eslint/no-explicit-any: error`, and CI enforces a bundle budget of 400 KB gzipped
JS / 100 KB CSS. Measured baseline before this work: **46.6 KB JS, 1.0 KB CSS**.

## Decision

Tailwind v4 through `@tailwindcss/vite`, with shadcn-vue components **copied into
`src/components/ui/` and owned in-tree** rather than installed as a dependency. Reka-UI
supplies the accessible primitives underneath.

Dark mode is a persisted class toggle: `@custom-variant dark (&:where(.dark, .dark *))`, a
`useTheme()` composable storing `light | dark | system`, and an inline script in `index.html`
that sets the class before first paint.

`style.css` is replaced, not layered on. All nine existing views are converted.

## Consequences

### Positive

- Version compatibility verified against this exact toolchain, not assumed:
  `@tailwindcss/vite@4.3.3` declares `vite: ^5.2 || ^6 || ^7 || ^8`, and `reka-ui@2.10.3`
  declares `vue: >= 3.4.0`. Vite 8 and Vue 3.5.40 both clear.
- Vendored components are readable local files. That matters more here than in most repos: this
  codebase is explicitly built to be extended by sessions with no prior context, and a
  component someone can open and edit beats one they must learn a theming API to override.
- No `tailwind.config.js` and no PostCSS config — v4 is CSS-first, so the configuration surface
  is one stylesheet.
- Accessibility comes from Reka-UI's primitives (focus traps, roving tabindex, ARIA wiring)
  rather than from remembering to add it.
- Projected total is roughly 150–190 KB gzipped JS and 12–25 KB CSS: comfortably inside budget,
  with about 2× headroom.

### Negative

- Vendored components are a fork. Upstream fixes do not arrive by `npm update`; someone must
  copy them.
- ~14 components of code appear in the repo at once, and they are not code anyone here wrote.
- Converting nine working views risks the accessible names `e2e/sign-in.spec.ts` depends on
  (`getByRole('navigation')`, `{exact: true}` on "Sign out", `role="alert"`). Those names are
  part of the contract now, whether or not they were meant to be.
- `style.css`'s stated non-opinion is abandoned. Anyone starting a project from this template
  now inherits a visual design, which is exactly the cost that comment warned about. Accepted
  because the template now ships an admin product, not just a login form.
- The pinned TypeScript 6 (ADR-0018) constrains which future versions of these packages can be
  adopted.

## Alternatives rejected and why

- **The `shadcn-vue` CLI (`init` / `add`)** — it resolves the `@/` alias from the root
  `tsconfig.json`, which here is solution-style (`"files": []` + `references`) with **no
  `compilerOptions`**; the alias lives in `tsconfig.app.json`. The CLI does not find it. Adding
  `paths` to the root config would work, but vendoring by hand is where `noUnusedLocals`,
  `verbatimModuleSyntax` and stray `any` violations get fixed anyway — all three of which fail
  this build.
- **PrimeVue 4** — a real DataTable for free, and the fastest route to dense admin tables.
  Rejected for bundle weight and for its own theming model: overriding it means learning an API
  rather than editing a file, which is the opposite of this repo's premise.
- **Vuetify 3** — Material Design is a strong visual opinion to impose on a template, and it is
  the heaviest option.
- **Tailwind utilities with no primitive library** — smallest footprint, and it means
  hand-writing focus management for dialogs, comboboxes and menus. That is where accessibility
  bugs live.
- **Keeping plain CSS with custom properties** — zero new dependencies and zero bundle cost. It
  loses to the fact that fifteen admin screens of hand-rolled components drift, and the drift is
  invisible until someone uses a screen reader.
- **`vue-sonner` for toasts** — 5 KB and pleasant. Its peer dependencies are Nuxt-shaped
  (`@nuxt/kit`, `@nuxt/schema`, `nuxt`); they are all marked `optional: true`, so it would have
  installed cleanly. Rejected only because Reka-UI's `Toast` primitive is already in the
  dependency set and one fewer package is worth more than the convenience.
- **Reka-UI's `Calendar` / `DateRangePicker`** — pulls `@internationalized/date` (~30 KB gz) to
  serve one audit-log filter. Two `<input type="date">` do the job.

## Reversal cost

**Expensive.** Every `.vue` file in the SPA would need rewriting. This is the least reversible
decision in the project, which is why the compatibility claims above were measured rather than
assumed.

## Implemented by

- `frontend/vite.config.ts` (`@tailwindcss/vite`), `frontend/src/style.css`
- `frontend/src/components/ui/`, `frontend/src/lib/utils.ts`
- `frontend/src/composables/useTheme.ts`, `frontend/index.html`
- `frontend/eslint.config.ts` (the `src/components/ui/**` override)
