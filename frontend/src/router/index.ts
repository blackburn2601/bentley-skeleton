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
      {
        path: '',
        name: 'home',
        component: () => import('@/views/HomeView.vue'),
        meta: { title: 'Home' },
      },
      {
        path: 'sign-in',
        name: 'sign-in',
        component: () => import('@/views/SignInView.vue'),
        meta: { guestOnly: true, title: 'Sign in' },
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
        meta: { title: 'Your account', breadcrumb: ['Account'] },
      },
      {
        path: 'account/sessions',
        name: 'sessions',
        component: () => import('@/views/SessionsView.vue'),
        meta: { title: 'Active sessions', breadcrumb: ['Account', 'Sessions'] },
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
          title: 'Users',
          permission: 'user.read',
          breadcrumb: ['Administration', 'Users'],
        },
      },
      {
        path: 'admin/users/:id',
        name: 'admin-user',
        component: () => import('@/views/admin/UserDetailView.vue'),
        meta: {
          title: 'User',
          permission: 'user.read',
          breadcrumb: ['Administration', 'Users', 'Detail'],
        },
      },
      {
        path: 'admin/groups',
        name: 'admin-groups',
        component: () => import('@/views/admin/GroupsListView.vue'),
        meta: {
          title: 'Groups',
          permission: 'group.read',
          breadcrumb: ['Administration', 'Groups'],
        },
      },
      {
        path: 'admin/roles',
        name: 'admin-roles',
        component: () => import('@/views/admin/RolesListView.vue'),
        meta: {
          title: 'Roles',
          permission: 'role.read',
          breadcrumb: ['Administration', 'Roles'],
        },
      },
      {
        path: 'admin/permissions',
        name: 'admin-permissions',
        component: () => import('@/views/admin/PermissionsListView.vue'),
        meta: {
          title: 'Permissions',
          permission: 'permission.read',
          breadcrumb: ['Administration', 'Permissions'],
        },
      },
      {
        path: 'admin/audit',
        name: 'admin-audit',
        component: () => import('@/views/admin/AuditLogView.vue'),
        meta: {
          title: 'Audit log',
          permission: 'audit.read',
          breadcrumb: ['Administration', 'Audit log'],
        },
      },
      {
        path: 'admin/forbidden',
        name: 'admin-forbidden',
        component: () => import('@/views/admin/ForbiddenView.vue'),
        meta: { title: 'No access', breadcrumb: ['Administration', 'No access'] },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Not found' },
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
