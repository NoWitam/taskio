<?php

use App\Modules\Users\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->group(function (): void {
    Route::get('/', [UsersController::class, 'index']);
});
