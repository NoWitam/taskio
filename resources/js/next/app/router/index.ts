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
    // PUBLIC invite-accept page (no app shell, no auth). Mirrors the login route's
    // `public` meta so the guard renders it pre-auth. The accept endpoints never
    // 401 (a 404 = unknown token, handled inline), so the api interceptor's
    // login-redirect is never triggered from here.
    path: '/invitations/:token',
    name: 'next.invitations.accept',
    component: () => import('../../pages/invitations/AcceptInvite.vue'),
    meta: { public: true, titleKey: 'acceptInvite.title' },
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
      {
        // Workspace member + invitation management (owner-gated in the UI; the
        // backend enforces it too). Reached from the user-menu "Manage members".
        path: 'settings/members',
        name: 'next.settings.members',
        component: () => import('../../pages/settings/MembersView.vue'),
        meta: { requiresAuth: true, titleKey: 'members.title' },
      },
      {
        // Forms module shell: inner sub-nav + the list / per-form sub-views.
        path: 'forms',
        component: () => import('../../pages/forms/FormsModuleLayout.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.forms' },
        children: [
          {
            path: '',
            name: 'next.forms',
            component: () => import('../../pages/forms/FormsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.forms' },
          },
          {
            // Opening a form lands on its submissions.
            path: ':id',
            name: 'next.forms.detail',
            redirect: (to) => ({ name: 'next.forms.submissions', params: { id: to.params.id } }),
          },
          {
            path: ':id/preview',
            name: 'next.forms.preview',
            component: () => import('../../pages/forms/FormPreviewView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.forms' },
          },
          {
            path: ':id/submissions',
            name: 'next.forms.submissions',
            component: () => import('../../pages/forms/FormSubmissionsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.forms' },
          },
          {
            path: ':id/reports',
            name: 'next.forms.reports',
            component: () => import('../../pages/forms/FormReportsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.forms' },
          },
        ],
      },
      {
        // Approvals module shell: inner sub-nav + the Queue (Batch 2, the primary
        // daily surface + the default child) and the Pipelines list (Batch 1). The
        // shell hosts the `?pipeline` builder drawer and the `?review` decision
        // drawer.
        path: 'approvals',
        component: () => import('../../pages/approvals/ApprovalsModuleLayout.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.approvals' },
        children: [
          {
            path: '',
            name: 'next.approvals',
            redirect: { name: 'next.approvals.queue' },
          },
          {
            path: 'queue',
            name: 'next.approvals.queue',
            component: () => import('../../pages/approvals/QueueView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.approvals' },
          },
          {
            path: 'pipelines',
            name: 'next.approvals.pipelines',
            component: () => import('../../pages/approvals/PipelinesView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.approvals' },
          },
        ],
      },
      {
        // Bots (AI Character) module shell: inner sub-nav + the list (Batch 1) and
        // the per-bot read-only detail. The shell hosts the `?bot` editor drawer.
        path: 'bots',
        component: () => import('../../pages/bots/BotsModuleLayout.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.bots' },
        children: [
          {
            path: '',
            name: 'next.bots',
            component: () => import('../../pages/bots/BotsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.bots' },
          },
          {
            path: ':id',
            name: 'next.bots.detail',
            component: () => import('../../pages/bots/BotDetailView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.bots' },
          },
        ],
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
