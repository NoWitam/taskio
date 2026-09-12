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
 * THE ORDER IS: STORED CHOICE → THE LANGUAGE THE CLIENT IS RENDERING → `APP_LOCALE`
 *
 * `Accept-Language` is still not consulted, and the original reasoning stands: a stored preference is a
 * CHOICE, that header is the browser's guess about a person who has not made one. Honouring the guess
 * would drag in q-values, region subtags and a fallback chain, and would answer the same endpoint
 * differently for visitors who are looking at the very same screen.
 *
 * The middle step is a DIFFERENT KIND OF FACT and that is the whole point. `X-Client-Locale` is not a
 * preference and not a negotiation: the SPA states the language it is rendering AT THIS MOMENT — the
 * one on the buttons the user is looking at while reading our answer. The frontend picks its own locale
 * from `localStorage` or, failing that, the browser, and it never told the server; the result was a
 * Polish interface receiving English validation messages, English calendar source labels and English
 * badges in the same window, from first login, with no way to correct it from the UI.
 *
 * Answering in the language of the screen the response lands on is not a guess about the person. It is
 * the minimum coherence of one page. So it is honoured — but only where there is no EFFECTIVE choice to
 * override, which is why it sits below the column and not above it. "No effective choice" means the
 * column is null OR holds something this installation cannot speak: an unusable stored value is not a
 * preference to respect, and falling back to the screen's language beats falling back past it.
 *
 * NOTHING HERE WRITES A GUESS INTO `users.locale`, and that is deliberate. The nullable column keeps
 * "chose English" apart from "never asked": a person who has not touched the switcher follows this
 * order afresh on every request and keeps following `APP_LOCALE` if the client says nothing. Persisting
 * what the client renders would freeze a browser default into the account as though it were a decision
 * — exactly the trap the `default 'en'` column used to set.
 *
 * NOTE THE PRE-AUTH CONSEQUENCE, because it is a real behaviour change: the login and password-reset
 * responses now follow the locale the login screen is rendering. That was the previously accepted cost
 * of the old rule (a Polish login form refusing a password in English), and it is the same defect as
 * the reported one — someone who has never logged in has certainly never chosen. Unauthenticated
 * requests that carry NO header (curl, integrations, the health check) are unaffected.
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
 * The header goes through the SAME guard and nothing softer: it is user input like any other, so it is
 * matched exactly against the supported set. No trimming, no case folding, no `pl-PL` → `pl` — that
 * would be the negotiation layer this deliberately does not have, and our own client sends the exact
 * strings from `config('app.supported_locales')`. A junk value changes nothing at all: an unsupported
 * header falls through to `APP_LOCALE` just as an unsupported column does.
 */
class SetUserLocale
{
    /**
     * The header the SPA uses to state the language it is CURRENTLY RENDERING.
     *
     * Deliberately not `Accept-Language`: that one is set by the browser for every request whether the
     * app asked for it or not, and conflating the two would make "our client told us" indistinguishable
     * from "some browser guessed". Only our own client sets this, and only ever with the locale its own
     * `useI18n()` is rendering — see `resources/js/next/app/lib/api.ts`.
     */
    public const CLIENT_HEADER = 'X-Client-Locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->chosen($request) ?? $this->rendered($request);

        if ($locale !== null) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    /** What the user decided. It outranks everything, including a client rendering something else. */
    private function chosen(Request $request): ?string
    {
        return $this->supportedOrNull($request->user()?->locale);
    }

    /** What the client says it is rendering right now. Only consulted when nothing was chosen. */
    private function rendered(Request $request): ?string
    {
        return $this->supportedOrNull($request->header(self::CLIENT_HEADER));
    }

    /** A locale this installation can actually speak, or null — never a value passed through untested. */
    private function supportedOrNull(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, self::supported(), true) ? $locale : null;
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

    /**
     * The language this INSTALLATION speaks when nobody has said otherwise — `APP_LOCALE`.
     *
     * This middleware never needs it (it simply declines to call `setLocale`, leaving the boot
     * value in place), but anything producing output that OUTLIVES the request does: a mail is
     * read in an inbox, not on the screen that asked for it, so it cannot follow `X-Client-Locale`
     * — on the password-reset flow anybody may name any address, and letting the request choose
     * would let a stranger pick the language of a letter delivered to the account holder.
     *
     * IT DOES NOT READ `config('app.locale')`, AND THAT IS THE WHOLE REASON IT EXISTS. `handle()`
     * above calls `App::setLocale()`, which WRITES that key (Foundation\Application::setLocale), so
     * after this middleware has run `config('app.locale')` holds THIS REQUEST'S language. Code
     * reaching for it as a default silently gets a caller-steerable value. `app.default_locale` is
     * the twin nothing rewrites; see the comment beside it in `config/app.php`.
     *
     * Guarded through {@see supported()} for the same reason the stored column is: a locale this
     * installation cannot speak renders every key as its own name.
     */
    public static function installationDefault(): string
    {
        $default = config('app.default_locale');
        $supported = self::supported();

        return is_string($default) && in_array($default, $supported, true)
            ? $default
            : $supported[0];
    }
}
