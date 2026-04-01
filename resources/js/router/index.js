import { createRouter, createWebHistory } from 'vue-router';
import dashboardRoutes from './modules/dashboard';
import tasksRoutes from './modules/tasks';
import formsRoutes from '@/modules/forms/routes';

function parseQuery(search = '') {
  const query = {};
  const s = String(search).replace(/^\?/, '');
  if (!s) return query;

  for (const part of s.split('&')) {
    if (!part) continue;
    const [rawK, rawV = ''] = part.split('=');
    const k0 = decodeURIComponent(rawK || '');
    const v = decodeURIComponent(rawV || '');
    if (!k0) continue;

    const isBracket = k0.endsWith('[]');
    const k = isBracket ? k0.slice(0, -2) : k0;

    if (Object.prototype.hasOwnProperty.call(query, k)) {
      const cur = query[k];
      query[k] = Array.isArray(cur) ? cur.concat([v]) : [cur, v];
    } else {
      query[k] = isBracket ? [v] : v;
    }
  }

  return query;
}

function stringifyQuery(query = {}) {
  const parts = [];

  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;

    const encK = encodeURIComponent(k);

    if (Array.isArray(v)) {
      for (const item of v) {
        if (item === undefined || item === null) continue;
        parts.push(`${encK}%5B%5D=${encodeURIComponent(String(item))}`);
      }
      continue;
    }

    parts.push(`${encK}=${encodeURIComponent(String(v))}`);
  }

  // vue-router dokleja '?' samodzielnie – tutaj zwracamy samą część query.
  return parts.join('&');
}

const routes = [
  {
    path: '/app',
    component: () => import('@/components/layouts/AppLayout.vue'),
    children: [
      ...dashboardRoutes,
      ...tasksRoutes,
      ...formsRoutes,
      {
        path: 'example',
        name: 'example',
        component: () => import('@/components/Example.vue'),
        meta: {
          module: 'example',
          title: 'Example',
          requiresAuth: false,
        },
      },
      {
        path: '',
        redirect: '/app/dashboard',
      },
    ],
  },
  {
    path: '/',
    redirect: '/app',
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  parseQuery,
  stringifyQuery,
});

router.beforeEach((to, from, next) => {
  // W przyszłości: sprawdzenie autentykacji
  // const userStore = useUserStore();
  // if (to.meta.requiresAuth && !userStore.isAuthenticated) {
  //   next({ name: 'login' });
  // } else {
  //   next();
  // }
  next();
});

export default router;
