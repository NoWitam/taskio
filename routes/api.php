<?php

use App\Http\Controllers\UserController;
use App\Modules\Forms\Models\Form;
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
    $id = "019d39a0-ecbb-7145-bacd-5a56524dd472";
    $form = Form::find($id);

    $form->normalizeFieldIds();
    dump($form->content);

    dump($form->getJsonSchema());
});