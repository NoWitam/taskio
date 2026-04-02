export default [
    {
        path: 'forms',
        name: 'forms',
        component: () => import('@/modules/forms/views/FormsModuleView.vue'),
        meta: {
            module: 'forms',
            title: 'Forms',
            requiresAuth: false,
        },
        children: [
            {
                path: '',
                name: 'forms.list',
                component: () => import('@/modules/forms/views/FormsListPage.vue'),
                meta: {
                    title: 'Forms List',
                },
            },
            {
                path: ':formId',
                name: 'forms.detail',
                redirect: { name: 'forms.detail.preview' },
                children: [
                    {
                        path: 'preview',
                        name: 'forms.detail.preview',
                        component: () => import('@/modules/forms/views/FormPreviewPage.vue'),
                        meta: {
                            title: 'Form Preview',
                        },
                    },
                    {
                        path: 'submissions',
                        name: 'forms.detail.submissions',
                        component: () => import('@/modules/forms/views/FormSubmissionsPage.vue'),
                        meta: {
                            title: 'Form Submissions',
                        },
                    },
                    {
                        path: 'reports',
                        name: 'forms.detail.reports',
                        component: () => import('@/modules/forms/views/FormReportsPage.vue'),
                        meta: {
                            title: 'Form Reports',
                        },
                    },
                ],
            },
        ],
    },
]
