<?php

use App\Http\Controllers\UserController;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::put('/user/locale', [UserController::class, 'updateLocale']);
});

Route::get('/test', function () {
    $id = "019d53f8-6a08-715d-80b5-2f948e700ee8";
    $form = Form::find($id);

    $form->normalizeFieldIds();

    dump($form->content);
    dump($form->getJsonSchema());
});