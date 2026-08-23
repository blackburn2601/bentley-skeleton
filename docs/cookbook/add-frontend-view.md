# Recipe: add a screen to the SPA

The frontend mirrors the backend's rule: **views contain no business logic**, API calls live
in `src/api/`, one module per topic.

## 1. The API module

`frontend/src/api/notes.ts` — one module per topic, mirroring the backend's single-topic
rule:

```ts
import { api } from './client'

export interface Note { id: string; title: string }

export const listNotes = () => api.get<Note[]>('/api/v1/notes')
```

Always go through `client.ts`. It sends cookies, adds the CSRF double-submit header, converts
`problem+json` into typed errors, and performs the **single-flight** 401 → refresh → replay.
A bare `fetch()` bypasses all four — most importantly the single-flight refresh, and
concurrent refreshes present already-rotated tokens, trip reuse detection, and log the user
out.

## 2. The store, if state is shared

Pinia store in `src/stores/`. Skip it for state that belongs to one component.

## 3. The view

`src/views/`. It renders and dispatches; it does not decide. Anything resembling a rule
belongs in a composable or the store.

## 4. The route

`src/router/index.ts`, with a guard if the screen requires authentication.

## 5. Permission-dependent UI

```vue
<button v-can="'note.delete'">Delete</button>
```

**This hides UI. It does not authorize anything** (INV-16). The endpoint enforces the
permission; the browser is not a trust boundary. Never treat `v-can` as protection — a hidden
button is still a reachable endpoint, and the IDOR suite exists because that gets forgotten.

## 6. Test

- Vitest for the component and any composable.
- Playwright for a flow that crosses screens.

## 7. Verify

```bash
make front-lint front-test front-build
```

`front-build` now runs the gzipped size budget (400 KB JS, 100 KB CSS) as well, so if you just
added a large dependency this really is where you find out. It used to say so without doing it
— the budget ran only in CI.

## 8. Reuse the shell rather than rebuilding it

An admin screen is normally a list, and `usePaginatedResource` + `DataTable` already own
paging, debounced filters, and the loading, empty and error states — including surfacing
`ApiError.requestId`, which is what makes a support ticket traceable. A list view that does its
own `loading = true` bookkeeping is a view that will drift from the others.

For permission-dependent navigation, use `usePermission()` or `useNavigation()`, **not**
`v-can`. The directive runs once on `mounted` and removes the element, so it cannot show an
entry that a grant made available a moment ago — which is precisely the behaviour INV-13
requires. `v-can` remains correct for a static control on a page the user has already
loaded.

## Checklist

- [ ] API calls in `src/api/<topic>.ts`, going through `client.ts`
- [ ] No `fetch()` outside `client.ts`
- [ ] View has no business logic
- [ ] Route guard where authentication is required
- [ ] `v-can` used for display only, with the endpoint enforcing the real check
- [ ] Vitest for the component, Playwright for the flow
- [ ] `make front-lint front-test front-build` green, budget included
- [ ] Permission-gated navigation uses `usePermission()`/`useNavigation()`, not `v-can`
