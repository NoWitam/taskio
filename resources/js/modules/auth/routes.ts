import type { RouteRecordRaw } from 'vue-router';

const authRoutes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/modules/auth/views/LoginPage.vue'),
    meta: {
      requiresAuth: false,
      title: 'Login',
    },
  },
];

export default authRoutes;
