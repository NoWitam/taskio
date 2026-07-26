<?php

namespace App\Modules\Disk;

use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Policies\FilePolicy;
use App\Modules\Disk\Policies\FolderPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DiskModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->commands([
            \App\Modules\Disk\Console\PruneTempFilesCommand::class,
            \App\Modules\Disk\Console\ReapStaleAiEditsCommand::class,
            \App\Modules\Disk\Console\ReapStaleDraftsCommand::class,
        ]);

        // The PDF-thumbnail rasterizer is injected behind an interface so tests can swap in a fake
        // (and the feature never hard-depends on poppler being installed at unit-test time).
        $this->app->bind(
            \App\Modules\Disk\Support\PdfRasterizer::class,
            \App\Modules\Disk\Support\PopplerPdfRasterizer::class,
        );
    }

    public function boot(): void
    {
        // The morph map is ENFORCED app-wide (every module registers its own aliases), which
        // means an unmapped model throws on getMorphClass(). File was unmapped, so it could
        // not be the SUBJECT of a morph at all — no labels (labelables.labelable_type), no
        // changelog (changelogs.subject_type). Registering these is the precondition for both.
        // Safe to enable: nothing could ever have persisted the FQCN (it would have thrown).
        Relation::enforceMorphMap([
            'file' => File::class,
            'folder' => Folder::class,
        ]);

        // Register policies
        Gate::policy(File::class, FilePolicy::class);
        Gate::policy(Folder::class, FolderPolicy::class);

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');
    }
}
