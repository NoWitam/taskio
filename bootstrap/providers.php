<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Users\UsersModuleServiceProvider::class,
    // Calendar owns a CONTRACT and a REGISTRY and knows none of its sources; the modules below register
    // themselves into it from their own boot(). Listed ahead of them for readability only — the registry
    // is bound in Calendar's register() and sources register in boot(), and Laravel runs every register()
    // before any boot(), so no ordering here can break registration.
    App\Modules\Calendar\CalendarModuleServiceProvider::class,
    App\Modules\Tasks\TasksModuleServiceProvider::class,
    App\Modules\Labels\LabelsModuleServiceProvider::class,
    App\Modules\FilterTabs\FilterTabsModuleServiceProvider::class,
    App\Modules\Disk\DiskModuleServiceProvider::class,
    App\Modules\Comments\CommentsModuleServiceProvider::class,
    App\Modules\Changelog\ChangelogModuleServiceProvider::class,
    App\Modules\Forms\FormsModuleServiceProvider::class,
    App\Modules\Approvals\ApprovalsModuleServiceProvider::class,
    App\Modules\Bot\BotModuleServiceProvider::class,
    App\Modules\Variables\VariablesModuleServiceProvider::class,
    App\Modules\Knowledge\KnowledgeModuleServiceProvider::class,
    App\Modules\Generator\GeneratorModuleServiceProvider::class,
    App\Modules\Workflows\WorkflowsModuleServiceProvider::class,
    App\Modules\Workspaces\WorkspacesModuleServiceProvider::class,
    App\Modules\Auth\AuthModuleServiceProvider::class,
    App\Modules\Settings\SettingsModuleServiceProvider::class,
];
