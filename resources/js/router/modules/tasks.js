const tasksRoutes = [
  {
    path: 'tasks',
    name: 'tasks',
    component: () => import('@/modules/tasks/views/TasksView.vue'),
    meta: {
      module: 'tasks',
      title: 'Tasks',
      requiresAuth: false,
    },
  },
];

export default tasksRoutes;
