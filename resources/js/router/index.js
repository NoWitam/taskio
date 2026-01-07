import { createRouter, createWebHistory } from 'vue-router';
import dashboardRoutes from './modules/dashboard';
import tasksRoutes from './modules/tasks';

const routes = [
  {
    path: '/app',
    component: () => import('@/components/layouts/AppLayout.vue'),
    children: [
      ...dashboardRoutes,
      ...tasksRoutes,
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
