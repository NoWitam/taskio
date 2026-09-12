<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\GroupsController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Password reset (R4 / D5) — PUBLIC by necessity
|--------------------------------------------------------------------------
|
| Both are unauthenticated: somebody who cannot log in is the only person who
| needs them. Possession of the mailed token is the authorization on the second,
| and there is nothing to authorize on the first — which is exactly why the
| service refuses to let the answer vary by address.
|
| THROTTLED, and the third argument is LOAD-BEARING (see Disk's routes for the
| long version): without an explicit key PREFIX, ThrottleRequests keys on the
| signature alone and every throttled route shares ONE counter. Unauthenticated
| here means the counter keys on the IP, so these two must not share a bucket
| with each other either — a flood of link requests would otherwise 429 the
| people trying to redeem the links they already have.
|
| This is only the PER-IP half. The per-ADDRESS half is the broker's own
| `auth.passwords.users.throttle` (60s between links for one account), which no
| amount of IP rotation gets around. Five a minute is a person mistyping their
| address, not a script walking a list.
*/
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1,auth-password-forgot')
    ->name('auth.password.forgot');

Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:10,1,auth-password-reset')
    ->name('auth.password.reset');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Group & permission management (workspace owner only — enforced in policy).
    Route::get('workspaces/{workspace}/groups', [GroupsController::class, 'index']);
    Route::post('workspaces/{workspace}/groups', [GroupsController::class, 'store']);
    Route::put('workspaces/{workspace}/groups/{group}', [GroupsController::class, 'update']);
    Route::delete('workspaces/{workspace}/groups/{group}', [GroupsController::class, 'destroy']);
});
