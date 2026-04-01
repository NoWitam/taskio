export default [
    {
        path: 'forms',
        name: 'forms',
        component: () => import('@/modules/forms/views/FormsPage.vue'),
        meta: {
            module: 'forms',
            title: 'Forms',
            requiresAuth: false,
        },
    },
]
