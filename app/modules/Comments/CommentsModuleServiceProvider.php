<?php

namespace App\Modules\Comments;

use App\Modules\Comments\Models\Comment;
use App\Modules\Comments\Policies\CommentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CommentsModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/routes/api.php');

        Gate::policy(Comment::class, CommentPolicy::class);
    }
}
