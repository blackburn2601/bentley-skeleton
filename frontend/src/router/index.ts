import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Routes, and the guard that keeps signed-out users off signed-in screens.
 *
 * The guard is **navigation convenience, not authorization** (INV-16). It stops someone
 * landing on a page that would immediately fail; it protects nothing, because the data lives
 * behind API calls the server authorizes independently. Anyone can request those directly.
 */

declare module 'vue-router' {
  interface RouteMeta {
    /** Redirect to sign-in when nobody is signed in. */
    requiresAuth?: boolean
    /** Redirect signed-in users away — sign-in and register pages. */
    guestOnly?: boolean
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { title: 'Home' },
  },
  {
    path: '/sign-in',
    name: 'sign-in',
    component: () => import('@/views/SignInView.vue'),
    meta: { guestOnly: true, title: 'Sign in' },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { guestOnly: true, title: 'Create an account' },
  },
  {
    path: '/verify-email',
    name: 'verify-email',
    component: () => import('@/views/VerifyEmailView.vue'),
    meta: { title: 'Confirm your email' },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/ForgotPasswordView.vue'),
    meta: { guestOnly: true, title: 'Reset your password' },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('@/views/ResetPasswordView.vue'),
    meta: { title: 'Choose a new password' },
  },
  {
    path: '/account',
    name: 'account',
    component: () => import('@/views/AccountView.vue'),
    meta: { requiresAuth: true, title: 'Your account' },
  },
  {
    path: '/account/sessions',
    name: 'sessions',
    component: () => import('@/views/SessionsView.vue'),
    meta: { requiresAuth: true, title: 'Active sessions' },
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
    return { name: 'account' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — bentley` : 'bentley'
})
