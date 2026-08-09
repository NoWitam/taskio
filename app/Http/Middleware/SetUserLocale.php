<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the SERVER speak the language the user chose.
 *
 * `users.locale` has been persisted (and offered in the UI) since the locale switcher shipped, and
 * nothing has ever read it back into the translator. Every `__()` on the server therefore rendered in
 * `APP_LOCALE`: validation messages, domain refusals, the relation vocabulary's labels. On a Polish
 * installation that is invisible — until somebody switches the interface to English and gets Polish
 * sentences back from the API, which is the state this fixes.
 *
 * ------------------------------------------------------------------------------------------------
 * THE FALLBACK IS `APP_LOCALE`, NOT `Accept-Language`
 *
 * A stored preference is a CHOICE; the header is the browser's guess about a person who has not made
 * one. Honouring the guess would mean the same unauthenticated endpoint answers in different languages
 * for different visitors — a support problem for the handful of pre-auth responses (a login failure, a
 * password reset) and a new behaviour rather than a fix for the reported one. It also drags in
 * q-values, region subtags and a fallback chain, which is a lot of machinery for two locales.
 *
 * So: an authenticated user with a valid stored locale gets it; everybody else gets the installation's.
 *
 * NULL IS THE SAME ANSWER AS "nobody logged in", and deliberately so. `users.locale` is nullable
 * precisely to keep "chose English" apart from "never asked": a person who has not touched the switcher
 * follows the installation, and keeps following it if `APP_LOCALE` later changes. Had the column kept
 * its old `default 'en'`, this middleware would have read a guess a migration made years ago as though
 * it were that person's decision — handing English to every new user of a Polish installation.
 *
 * ------------------------------------------------------------------------------------------------
 * ORDERING
 *
 * It must run AFTER `Authenticate`, because it reads the resolved user — the same dependency
 * `ResolveWorkspace` has. Unlike that one it needs no explicit priority slot: it is absent from the
 * framework's priority list, so the sort leaves it where the group append put it, which is after every
 * prioritised middleware. Pinned by {@see \Tests\Feature\UserLocaleTest}, because "it happens to land
 * late" is exactly the kind of guarantee that quietly stops holding.
 *
 * It deliberately does NOT need to run before `SubstituteBindings`: nothing about model binding depends
 * on the locale, so this carries none of the security weight the workspace ordering does.
 *
 * A locale outside the supported set is IGNORED rather than applied — a column can hold anything a past
 * migration or a direct write put there, and `setLocale('xx')` silently renders every key as itself.
 */
class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (is_string($locale) && in_array($locale, self::supported(), true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    /**
     * The locales this installation ships translations for.
     *
     * Read from config by BOTH this middleware and the endpoint that writes the column, so the value
     * that steers the translator and the value the writer is allowed to store cannot drift apart.
     *
     * @return array<int, string>
     */
    public static function supported(): array
    {
        $supported = config('app.supported_locales');

        return is_array($supported) && $supported !== [] ? array_values($supported) : ['en'];
    }
}
