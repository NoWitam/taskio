<?php

use App\Modules\Labels\Http\Controllers\LabelsController;
use Illuminate\Support\Facades\Route;

Route::resource('labels', LabelsController::class)->only([
    'index', 'store'
]);
