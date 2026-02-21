<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Update user locale preference
     *
     * @param Request $request
     * @return array
     */
    public function updateLocale(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(['en', 'pl'])],
        ]);

        $request->user()->update([
            'locale' => $validated['locale'],
        ]);

        return [
            'message' => 'Locale updated successfully',
            'locale' => $request->user()->locale,
        ];
    }

    /**
     * Get current user
     *
     * @param Request $request
     * @return \App\Models\User|null
     */
    public function getUser(Request $request)
    {
        return $request->user();
    }
}
