<?php

return [
    'module_name' => 'Disk',

    // The read-only "Zasoby" tree over files owned by other resources.
    'resources' => [
        'root' => 'Resources',
        'task' => 'Task attachments',
        'form_report' => 'Reports',
        'form_submission' => 'Form attachments',
    ],

    'ai' => [
        'failed' => 'The AI edit failed. Please try again.',
        'budget' => "You have reached today's AI image limit. Try again tomorrow.",
        // The provider rendered the image and then refused to return it (its own moderation).
        // Says what actually happened and what the user can do — retrying changes nothing.
        'safety' => 'The provider refused this content for safety reasons. Adjust the description or the outfit and try again.',
    ],

    'drafts' => [
        'too_large' => 'This draft is too large to autosave.',
    ],

    'validation' => [
        'folder_not_empty' => 'This folder is not empty. Move or remove its contents first.',
        'folder_name_taken' => 'A folder with this name already exists here.',
        'folder_move_into_self' => 'A folder cannot be moved into itself.',
        'folder_move_into_descendant' => 'A folder cannot be moved into one of its own subfolders.',
        'folder_too_deep' => 'Folders cannot be nested deeper than :max levels.',
        'file_owned_by_resource' => 'This file belongs to another item; manage it there instead.',
        'restore_target_required' => [
            'detached' => 'This file was attached to another item, so it cannot return there. Choose a folder to restore it into.',
            'folder_missing' => 'Its original folder no longer exists. Choose a folder to restore it into.',
            'folder_trashed' => 'Its original folder is in the trash. Choose a folder to restore it into.',
        ],
    ],
];
