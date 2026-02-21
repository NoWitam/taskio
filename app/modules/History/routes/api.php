<?php

use App\Modules\History\Http\Controllers\HistoryController;
use Illuminate\Support\Facades\Route;

Route::get('{module}/{id}/history', [HistoryController::class, 'index'])->name('history.index');
