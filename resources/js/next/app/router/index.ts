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
import { sectionRedirect } from './sectionRedirect';

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
        // The disk file manager. The current folder is a PATH param (`/next/disk/<id>`,
        // deep-linkable); the optional `:folder` also carries the synthetic `sys:res…` /
        // `sys:trash` ids (single segment, no slash), so one route serves the whole tree.
        path: 'disk/:folder?',
        name: 'next.disk',
        component: () => import('../../pages/disk/DiskView.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.disk' },
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
            // Detail sections are CHILD ROUTES sharing one component (the view
            // derives the section from the route name). The record itself has NO
            // component, so the children render in the module layout's
            // <RouterView>. The bare path keeps the legacy `next.bots.detail`
            // name: named pushes land on the default section, and old
            // `?section=` deep links redirect with every other query key intact.
            path: ':id',
            children: [
              {
                path: '',
                name: 'next.bots.detail',
                redirect: (to) =>
                  sectionRedirect(to, 'next.bots.detail.', ['inbox', 'activity', 'config'], 'inbox'),
              },
              {
                path: 'inbox',
                name: 'next.bots.detail.inbox',
                component: () => import('../../pages/bots/BotDetailView.vue'),
                meta: { requiresAuth: true, titleKey: 'nav.bots' },
              },
              {
                path: 'activity',
                name: 'next.bots.detail.activity',
                component: () => import('../../pages/bots/BotDetailView.vue'),
                meta: { requiresAuth: true, titleKey: 'nav.bots' },
              },
              {
                path: 'config',
                name: 'next.bots.detail.config',
                component: () => import('../../pages/bots/BotDetailView.vue'),
                meta: { requiresAuth: true, titleKey: 'nav.bots' },
              },
            ],
          },
        ],
      },
      {
        // Workflows (automation) module shell: inner sub-nav + the list and the
        // per-workflow read-only detail (Overview | Runs). The shell hosts the
        // `?workflow` editor drawer (6b) and the `?run` run-now modal (6c).
        path: 'workflows',
        component: () => import('../../pages/workflows/WorkflowsModuleLayout.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.workflows' },
        children: [
          {
            path: '',
            name: 'next.workflows',
            component: () => import('../../pages/workflows/WorkflowsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.workflows' },
          },
          {
            // Global cross-workflow runs feed. DECLARED BEFORE the dynamic `:id`
            // record so the static segment wins (Vue Router 4 ranks static above
            // dynamic regardless of order, but declaring it first keeps intent clear).
            path: 'runs',
            name: 'next.workflows.runs',
            component: () => import('../../pages/workflows/WorkflowRunsListView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.workflows' },
          },
          {
            // Detail sections are CHILD ROUTES of the detail SHELL (the shell
            // owns the fetch + action bar and renders the section through its
            // own <RouterView> — Runs needs its own lifecycle). The bare path
            // keeps the legacy `next.workflows.detail` name: named pushes land
            // on the default section, and old `?section=` deep links redirect
            // with every other query key (run, run_detail, state, …) intact.
            path: ':id',
            component: () => import('../../pages/workflows/WorkflowDetailView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.workflows' },
            children: [
              {
                path: '',
                name: 'next.workflows.detail',
                redirect: (to) =>
                  sectionRedirect(to, 'next.workflows.detail.', ['overview', 'runs'], 'overview'),
              },
              {
                path: 'overview',
                name: 'next.workflows.detail.overview',
                component: () => import('../../pages/workflows/WorkflowOverviewView.vue'),
                meta: { requiresAuth: true, titleKey: 'nav.workflows' },
              },
              {
                path: 'runs',
                name: 'next.workflows.detail.runs',
                component: () => import('../../pages/workflows/WorkflowRunsSection.vue'),
                meta: { requiresAuth: true, titleKey: 'nav.workflows' },
              },
            ],
          },
        ],
      },
      {
        // Top-level "Variables" (PL "Zmienne") module shell: inner sub-nav + its pages.
        // Consts (PL "Stałe") is the workspace typed literal constants — formerly the
        // workflows "globals" sub-page — moved out to its own top-level area. The RUNTIME
        // wire root stays `globals` (a const is still `globals.<key>`); only the URL/nav
        // moved. Functions (PL "Funkcje") join this nav in Phase 3c.
        path: 'variables',
        component: () => import('../../pages/variables/VariablesModuleLayout.vue'),
        meta: { requiresAuth: true, titleKey: 'nav.variables' },
        children: [
          {
            path: '',
            name: 'next.variables',
            redirect: { name: 'next.variables.consts' },
          },
          {
            path: 'consts',
            name: 'next.variables.consts',
            component: () => import('../../pages/variables/ConstantsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.variables' },
          },
          {
            // Custom FUNCTIONS (Phase 3c): user-authored variable transforms (input + typed
            // args → return, over a body pipeline). Surface as `fn:<uuid>` ops in every
            // workflow pipeline; managed here alongside Consts.
            path: 'functions',
            name: 'next.variables.functions',
            component: () => import('../../pages/variables/FunctionsView.vue'),
            meta: { requiresAuth: true, titleKey: 'nav.variables' },
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
