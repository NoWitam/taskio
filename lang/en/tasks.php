<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tasks Module Translations (English)
    |--------------------------------------------------------------------------
    |
    | Translations for tasks module - labels, messages and status strings
    |
    */

    'title' => 'Tasks',
    'task' => 'Task',
    'tasks' => 'Tasks',
    'description' => 'Description',
    'newTask' => 'New Task',
    'newTaskDescription' => 'Create a new task and assign it to a user',
    'editTask' => 'Edit Task',
    'editTaskDescription' => 'Update task information',
    'taskName' => 'Task Name',
    'taskCreated' => 'Task Created',
    'taskCreatedMessage' => 'Task :title has been created successfully.',
    'taskUpdated' => 'Task Updated',
    'taskUpdatedMessage' => 'Task :title has been updated successfully.',
    'taskCreateFailed' => 'Task Creation Failed',
    'taskUpdateFailed' => 'Task Update Failed',
    'checkData' => 'Please check the entered data.',
    'searchPlaceholder' => 'Search tasks...',
    'descriptionPlaceholder' => 'Describe the task...',
    'noDeadline' => 'No deadline',
    'noTasks' => 'No tasks',
    'list' => 'List',
    'toDo' => 'To do',
    'inProgress' => 'In progress',
    'inTest' => 'In test',
    'done' => 'Done',
    'archive' => 'Archive',
    'trash' => 'Trash',
    'archiveInfo' => 'Archived tasks',
    'trashInfo' => 'Deleted tasks',
    'daysOverdue' => ':count days overdue|:count day overdue|:count days overdue',
    'deadlineApproaching' => 'Deadline in :days',
    'deadlineDay' => 'day',
    'deadlineDays' => 'days',
    'from' => 'From',
    'to' => 'To',
    'deadline' => 'Deadline',
    'without_deadline' => 'Without deadline',
    'activeFilters' => 'Active Filters',
    'allPriorities' => 'All Priorities',
    'urgentPriority' => 'Urgent',
    'highPriority' => 'High',
    'mediumPriority' => 'Medium',
    'lowPriority' => 'Low',
    'labels_and' => 'All labels (AND)',
    'labels_or' => 'Any label (OR)',
    'form' => 'Form',
    'attachForm' => 'Attach Form',
    'attachments' => 'Attachments',

    'fields' => [
        'title' => 'Title',
        'description' => 'Description',
        'priority' => 'Priority',
        'status' => 'Status',
        'deadline' => 'Deadline',
        'assigned_to' => 'Assigned to',
        'assignee' => 'Assignee',
        'labels' => 'Labels',
        'files' => 'Files',
        'comments' => 'Comments',
    ],

    'status' => [
        'to_do' => 'To do',
        'in_progress' => 'In progress',
        'in_test' => 'In test',
        'done' => 'Done',
        'archived' => 'Archived',
        'trash' => 'Trash',
    ],

    'priority' => [
        'urgent' => 'Urgent',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ],

    'priorityUrgent' => 'Urgent',
    'priorityHigh' => 'High',
    'priorityMedium' => 'Medium',
    'priorityLow' => 'Low',
    'assignedTo' => 'Assigned to',
    'labels' => 'Labels',

    'validation' => [
        'bot_cannot_execute' => 'This bot cannot execute tasks. Enable the task-execution module on the bot (and activate it) before assigning it.',
    ],

    'messages' => [
        'created' => 'Task created successfully',
        'updated' => 'Task updated successfully',
        'deleted' => 'Task deleted successfully',
        'restored' => 'Task restored successfully',
        'archived' => 'Task archived successfully',
        'task_not_found' => 'Task not found',
        'no_tasks' => 'No tasks found',
    ],

    'taskDetails' => [
        'tabHistory' => 'History',
        'tabForm' => 'Form',
        'tabChecklist' => 'Checklist',
        'tabApproval' => 'Approval',
        'added' => 'Added (:count)',
        'removed' => 'Removed (:count)',
        'assignedUser' => 'Assigned User',
        'createdBy' => 'Created By',
        'deadline' => 'Deadline',
        'description' => 'Description',
        'noDescription' => 'No description',
        'noValue' => 'None',
        'noChanges' => 'No changes in history',
        'formPreparing' => 'Preparing form...',
        'checklistPreparing' => 'Preparing checklist...',
        'approvalPreparing' => 'Preparing approval...',
        'noFormAttached' => 'No form attached to this task',
    ],

    // R3 Calendar. The source name is translated SERVER-side and carried in the calendar response,
    // because otherwise adding a source would mean editing the frontend — and this module's whole
    // contract is that it does not. Occurrence badges reuse the existing `tasks.status.*` keys rather
    // than duplicating them here.
    'calendar' => [
        'source' => 'Task deadlines',
    ],
];
