<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetUserLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Update user locale preference
     *
     * @return array
     */
    public function updateLocale(Request $request)
    {
        // Validated against the SAME list `SetUserLocale` reads. It always was validated; it now also
        // STEERS the translator on every later request, so the two have to be one list — accepting a
        // value the reader ignores would leave a user whose chosen language silently never applies.
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetUserLocale::supported())],
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
     * @return \App\Models\User|null
     */
    public function getUser(Request $request)
    {
        return $request->user();
    }
}
