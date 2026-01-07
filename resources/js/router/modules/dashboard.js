const dashboardRoutes = [
  {
    path: 'dashboard',
    name: 'dashboard',
    component: () => import('@/modules/dashboard/views/DashboardView.vue'),
    meta: {
      module: 'dashboard',
      title: 'Dashboard',
      requiresAuth: false,
    },
  },
];

export default dashboardRoutes;
