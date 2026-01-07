<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

// Główna strona - przekierowanie na aplikację
Route::get('/', function () {
    return redirect('/app');
});

// Główna trasa aplikacji - renderuje Blade widok z wstrzykniętymi danymi
Route::get('/app{path?}', [AppController::class, 'index'])
    ->where('path', '.*')
    ->name('app');

