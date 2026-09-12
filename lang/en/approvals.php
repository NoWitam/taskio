<?php

return [
    'module_name' => 'Approvals',

    'entity_types' => [
        'task' => 'Task',
        // R4 B6 — the second Approvable in the product. Worded as the thing being decided about, not as
        // the module, because it is what an approver reads at the top of a card.
        'publication' => 'Publication',
    ],

    'status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'approver_type' => [
        'user' => 'User',
        'ai' => 'AI',
    ],

    'actions' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'create_pipeline' => 'Create pipeline',
        'edit_pipeline' => 'Edit pipeline',
        'delete_pipeline' => 'Delete pipeline',
        'add_stage' => 'Add stage',
        'remove_stage' => 'Remove stage',
    ],

    'labels' => [
        'pipeline' => 'Approval pipeline',
        'pipelines' => 'Approval pipelines',
        'stage' => 'Stage',
        'stages' => 'Stages',
        'queue' => 'Approval queue',
        'name' => 'Name',
        'description' => 'Description',
        'icon' => 'Icon',
        'approver' => 'Approver',
        'criteria' => 'Evaluation criteria',
        'note' => 'Note',
        'decision' => 'Decision',
        'no_items' => 'No items to approve',
        'no_pipelines' => 'No approval pipelines',
        'approval_in_progress' => 'Approval in progress',
        'stage_of' => 'Stage :current of :total',
        'rejected_note' => 'Rejection reason',
    ],

    'validation' => [
        'pipeline_has_active_processes' => 'Cannot edit/delete pipeline with active approval processes.',
        'no_pipeline_assigned' => 'Entity has no approval pipeline assigned.',
        'pipeline_has_no_stages' => 'Approval pipeline has no stages defined.',
        'already_decided' => 'This stage has already been decided.',
        // R4 B6 — the subject was deleted while it was with an approver. A 422 rather than the TypeError
        // this used to be; see ApprovalService::decide().
        'approvable_missing' => 'The item this approval is about no longer exists.',
        // R4 B6 — one live review per subject; a second one would mean two approvers deciding about
        // the same thing without knowing of each other. See ApprovalService::startProcess().
        'process_already_pending' => 'This item is already under review.',
        'note_required_on_rejection' => 'A note is required when rejecting.',
        'invalid_decision' => 'Invalid decision.',
        'min_one_stage' => 'Pipeline must have at least one stage.',
    ],

    'messages' => [
        'approved' => 'Stage has been approved.',
        'rejected' => 'Stage has been rejected.',
        'pipeline_created' => 'Approval pipeline has been created.',
        'pipeline_updated' => 'Approval pipeline has been updated.',
        'pipeline_deleted' => 'Approval pipeline has been deleted.',
        'process_started' => 'Approval process has been started.',
    ],
];
