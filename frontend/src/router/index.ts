import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Routes, and the guard that keeps signed-out users off signed-in screens.
 *
 * The guard is **navigation convenience, not authorization** (INV-16). It stops someone
 * landing on a page that would immediately fail; it protects nothing, because the data lives
 * behind API calls the server authorizes independently. Anyone can request those directly.
 *
 * Layouts are parent routes rather than a component the view switches on: `meta` inherits down
 * the tree, so `requiresAuth` is declared once on the shell instead of on every screen inside
 * it, and the shell is not bundled into each lazily loaded view.
 */

declare module 'vue-router' {
  interface RouteMeta {
    /** Redirect to sign-in when nobody is signed in. */
    requiresAuth?: boolean
    /** Redirect signed-in users away — the sign-in page. */
    guestOnly?: boolean
    /**
     * The MFA verify screen: only a caller in the half-authenticated pending state may stay
     * here (ADR-0026). Anyone else is moved on — a verified user to their account, a guest to
     * sign-in.
     */
    mfaRequired?: boolean
    /**
     * Class-level permission this screen needs.
     *
     * Display convenience only (INV-16): the endpoints behind the screen enforce it.
     */
    permission?: string
    title?: string
    breadcrumb?: string[]
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      // There is no landing page: the root address *is* the sign-in screen. Signed-in visitors
      // do not stop here either — `sign-in` is `guestOnly`, so the guard below moves them on to
      // their account. One address, one answer, whoever is asking.
      {
        path: '',
        redirect: { name: 'sign-in' },
      },
      {
        path: 'sign-in',
        name: 'sign-in',
        component: () => import('@/views/SignInView.vue'),
        meta: { guestOnly: true, title: 'Anmelden' },
      },
      {
        path: 'mfa',
        name: 'mfa',
        component: () => import('@/views/MfaVerifyView.vue'),
        meta: { mfaRequired: true, title: 'Zwei-Faktor-Authentifizierung' },
      },
    ],
  },
  {
    path: '/',
    component: () => import('@/layouts/ShellLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: 'account',
        name: 'account',
        component: () => import('@/views/AccountView.vue'),
        meta: { title: 'Ihr Konto', breadcrumb: ['Konto'] },
      },
      {
        path: 'account/sessions',
        name: 'sessions',
        component: () => import('@/views/SessionsView.vue'),
        meta: { title: 'Aktive Sitzungen', breadcrumb: ['Konto', 'Sitzungen'] },
      },
      {
        path: 'admin',
        name: 'admin',
        component: () => import('@/views/admin/DashboardView.vue'),
        meta: { title: 'Administration', breadcrumb: ['Administration'] },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: () => import('@/views/admin/UsersListView.vue'),
        meta: {
          title: 'Benutzer',
          permission: 'user.read',
          breadcrumb: ['Administration', 'Benutzer'],
        },
      },
      {
        path: 'admin/users/:id',
        name: 'admin-user',
        component: () => import('@/views/admin/UserDetailView.vue'),
        meta: {
          title: 'Benutzer',
          permission: 'user.read',
          breadcrumb: ['Administration', 'Benutzer', 'Details'],
        },
      },
      {
        path: 'admin/groups',
        name: 'admin-groups',
        component: () => import('@/views/admin/GroupsListView.vue'),
        meta: {
          title: 'Gruppen',
          permission: 'group.read',
          breadcrumb: ['Administration', 'Gruppen'],
        },
      },
      {
        path: 'admin/roles',
        name: 'admin-roles',
        component: () => import('@/views/admin/RolesListView.vue'),
        meta: {
          title: 'Rollen',
          permission: 'role.read',
          breadcrumb: ['Administration', 'Rollen'],
        },
      },
      {
        path: 'admin/permissions',
        name: 'admin-permissions',
        component: () => import('@/views/admin/PermissionsListView.vue'),
        meta: {
          title: 'Berechtigungen',
          permission: 'permission.read',
          breadcrumb: ['Administration', 'Berechtigungen'],
        },
      },
      {
        path: 'admin/audit',
        name: 'admin-audit',
        component: () => import('@/views/admin/AuditLogView.vue'),
        meta: {
          title: 'Audit-Protokoll',
          permission: 'audit.read',
          breadcrumb: ['Administration', 'Audit-Protokoll'],
        },
      },
      {
        path: 'admin/forbidden',
        name: 'admin-forbidden',
        component: () => import('@/views/admin/ForbiddenView.vue'),
        meta: { title: 'Kein Zugriff', breadcrumb: ['Administration', 'Kein Zugriff'] },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Nicht gefunden' },
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // On a hard load the store has not asked /me yet. Waiting once, here, avoids a flash of the
  // sign-in page for someone who is in fact signed in.
  if (!auth.resolved) {
    await auth.load()
  }

  // A half-authenticated caller (password OK, second factor owed) may go nowhere but the MFA
  // screen (ADR-0026). This comes first: a pending user is not `isAuthenticated`, so the
  // requiresAuth and guestOnly branches below would otherwise bounce them to sign-in.
  if (auth.mfaPending && to.name !== 'mfa') {
    return { name: 'mfa' }
  }

  // The MFA screen is for pending callers only. A verified user has no business here; a guest
  // would see an empty form that can never succeed.
  if (to.meta.mfaRequired && !auth.mfaPending) {
    return auth.isAuthenticated ? { name: 'account' } : { name: 'sign-in' }
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    // `redirect` so the user lands where they were going, rather than on a generic home page
    // that makes them navigate again.
    return { name: 'sign-in', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    // Account, not the admin dashboard: most signed-in users are not administrators, and
    // landing them on a page listing what they cannot do is a poor greeting.
    return { name: 'account' }
  }

  // Reached by typing a URL, or by following a stale link after a permission was revoked. The
  // endpoint would refuse it anyway; this only chooses a better page than an empty table.
  if (to.meta.permission !== undefined && !auth.can(to.meta.permission)) {
    return { name: 'admin-forbidden' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — bentley` : 'bentley'
})
