// Router for the isolated "next" frontend.
//
// History mode is rooted at `/next` (the Laravel catch-all serves the shell), so
// route paths here are relative to that base. Real app pages live under the App
// Shell (AppLayout) and require auth; the login page is public and shellless. A
// global guard re-hydrates the session once (auth.init) and gates `requiresAuth`
// routes, redirecting to /login?redirect=<path> when signed out and away from
// /login when already signed in.
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'next.login',
    component: () => import('../../pages/auth/LoginPage.vue'),
    meta: { public: true, titleKey: 'auth.title' },
  },
  {
    // Authenticated app shell. Children render through AppLayout's <router-view>.
    path: '/',
    component: () => import('../../pages/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'next.home',
        redirect: { name: 'next.dashboard' },
      },
      {
        path: 'dashboard',
        name: 'next.dashboard',
        component: () => import('../../pages/DashboardView.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.dashboard' },
      },
      {
        path: 'tasks',
        name: 'next.tasks',
        component: () => import('../../pages/tasks/TasksView.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.tasks' },
      },
    ],
  },
  {
    // Public design-system gallery (kept reachable for docs).
    path: '/_styleguide',
    name: 'next.styleguide',
    component: () => import('../../docs/StyleguideView.vue'),
    meta: { public: true, title: 'Styleguide' },
  },
  {
    // Unknown path → the dashboard (the guard redirects to login if needed).
    path: '/:pathMatch(.*)*',
    redirect: { name: 'next.dashboard' },
  },
];

export const router = createRouter({
  history: createWebHistory('/next'),
  routes,
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();

  // Re-hydrate a stored token once before the first guarded navigation resolves.
  if (!auth.ready) {
    await auth.init();
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth);

  // Gate protected routes: bounce to login, preserving where the user was headed.
  if (requiresAuth && !auth.isAuthenticated) {
    return { name: 'next.login', query: { redirect: to.fullPath } };
  }

  // An authenticated user has no business on the login page.
  if (to.name === 'next.login' && auth.isAuthenticated) {
    return { name: 'next.dashboard' };
  }

  return true;
});
