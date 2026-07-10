<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Users\UsersModuleServiceProvider::class,
    App\Modules\Tasks\TasksModuleServiceProvider::class,
    App\Modules\Labels\LabelsModuleServiceProvider::class,
    App\Modules\FilterTabs\FilterTabsModuleServiceProvider::class,
    App\Modules\Disk\DiskModuleServiceProvider::class,
    App\Modules\Comments\CommentsModuleServiceProvider::class,
    App\Modules\Changelog\ChangelogModuleServiceProvider::class,
    App\Modules\Forms\FormsModuleServiceProvider::class,
    App\Modules\Approvals\ApprovalsModuleServiceProvider::class,
    App\Modules\Bot\BotModuleServiceProvider::class,
    App\Modules\Workflows\WorkflowsModuleServiceProvider::class,
    App\Modules\Workspaces\WorkspacesModuleServiceProvider::class,
    App\Modules\Auth\AuthModuleServiceProvider::class,
    App\Modules\Settings\SettingsModuleServiceProvider::class,
];
