<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Models\User;
use App\Modules\Settings\Http\Requests\UpdatePasswordRequest;
use App\Modules\Settings\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController
{
    public function show(Request $request)
    {
        return response()->json($this->payload($request->user()));
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json($this->payload($user->fresh()));
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $user = $request->user();
        $user->update(['password' => $request->string('password')->toString()]);

        // Revoke every other session; keep the token used for this request.
        $current = PersonalAccessToken::findToken($request->bearerToken() ?? '');
        $user->tokens()
            ->when($current, fn ($query) => $query->where('id', '!=', $current->id))
            ->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
        ];
    }
}
