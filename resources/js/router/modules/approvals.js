export default [
    {
        path: 'approvals',
        name: 'approvals',
        component: () => import('@/modules/approvals/views/ApprovalsModuleView.vue'),
        meta: {
            module: 'approvals',
            title: 'Approvals',
            requiresAuth: false,
        },
        children: [
            {
                path: '',
                name: 'approvals.queue',
                component: () => import('@/modules/approvals/views/ApprovalQueuePage.vue'),
                meta: {
                    title: 'Approval Queue',
                },
            },
            {
                path: 'pipelines',
                name: 'approvals.pipelines',
                component: () => import('@/modules/approvals/views/ApprovalPipelinesPage.vue'),
                meta: {
                    title: 'Approval Pipelines',
                },
            },
        ],
    },
];
