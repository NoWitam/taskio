<?php

use App\Modules\Tasks\Http\Controllers\TasksController;
use Illuminate\Support\Facades\Route;

Route::apiResource('tasks', TasksController::class);