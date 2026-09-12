# ADR-0053 — Locale resolution order: stored choice, then the language the client renders, then the installation

**Date:** 2026-08-26
**Status:** Accepted
**Module:** `App\Http\Middleware\SetUserLocale` (registered on the `api` middleware group only —
`bootstrap/app.php`), `resources/js/next/app/i18n/index.ts` (`activeLocale()`, `setLocale()`),
`resources/js/next/app/lib/api.ts` (`CLIENT_LOCALE_HEADER`), `resources/js/next/app/lib/token.ts`
(`hasAuthToken()`, `TOKEN_KEY` — the new home; re-exported from `api.ts` for existing importers)
**Relates to:** `database/migrations/2026_08_08_000000_make_users_locale_nullable.php` (the
nullable-column decision this ADR **refines**, not reverses), `tests/Feature/UserLocaleTest.php`
(every property below is pinned there)

---

## Context

`users.locale` had been persisted by the locale switcher since it shipped, and read back by nothing:
every `__()` on the server rendered in `APP_LOCALE` regardless of who was asking. On a Polish
installation that is invisible — the defect only surfaces the moment somebody switches the interface
to English and the API keeps answering in Polish: validation messages, domain refusals, the relation
vocabulary's own labels, all in the wrong language, in the same window as a correctly-English screen.

The obvious fix — read `users.locale` in a middleware and call `App::setLocale()` — is not sufficient
on its own, for a reason the nullable-column migration (2026-08-08) had already surfaced but not yet
closed: the frontend resolves its own locale from `localStorage` or the browser and, before this
change, only ever told the server about it on a deliberate click of the switcher. A brand-new user
whose browser renders Polish, who has never touched the switcher, has a `null` column — reading the
column alone leaves that request with no locale opinion at all, falling through to `APP_LOCALE`. On an
English-default installation that new user sees a Polish interface issuing English refusals from
their very first form submission, which is the identical symptom the report described, just for a
different population.

## Decisions

### Decision 1 — the three-step order, and why each step sits where it does

`SetUserLocale::handle()` resolves, in this order, stopping at the first hit:

1. **`users.locale`** — the user's stored **choice**. Read via `$request->user()?->locale`, filtered
   through `supportedOrNull()`. Wins over everything, including a client that is at this moment
   rendering something else.
2. **The `X-Client-Locale` request header** — the language the SPA states it is **currently
   rendering**. Consulted **only** when there is no effective choice: the column is `null`, or it
   holds a value this installation's `config('app.supported_locales')` does not list. An unusable
   stored value is not a preference to respect — there is nothing to override, so the language on the
   screen the response lands on wins exactly as it would for a genuinely empty column.
3. **`APP_LOCALE`** — the installation's own default, reached when both of the above say nothing
   (unauthenticated request with no header; authenticated request with no choice and no header — curl,
   an integration, a health check).

The order is a ranking of **what kind of fact each source is**, not an arbitrary priority list:

- A stored column is a decision a person made, on purpose, through the switcher. Nothing outranks it.
- A header sent by our own client is not a decision about the *person* — it is the SPA reporting the
  language on the buttons the user is looking at, right now, while reading the response the server is
  about to send. Answering in the language of the screen a response lands on is the minimum coherence
  of one page; it is honoured precisely because it is a *weaker* claim than a stored choice, which is
  also why it is checked second and not first.
- The installation default is what is left when neither the person nor the screen has an opinion.

### Decision 2 — `Accept-Language` stays out; `X-Client-Locale` is a fact about the session, not a preference

`Accept-Language` is still never consulted, and the reasoning that kept it out of the original
(column-only) design still holds unchanged: it is the **browser's guess**, sent on every request
whether the app asked for it or not, carrying q-values, region subtags and a negotiation chain this
codebase has never needed. Honouring it would answer the very same endpoint differently for two people
looking at the same screen, and would make "our client told us" indistinguishable from "some browser
guessed on the visitor's behalf."

`X-Client-Locale` is deliberately a **different kind of input**: only Taskio's own `next` client sets
it (`resources/js/next/app/lib/api.ts`, read at request time from `activeLocale()` — never at module
load, so it follows a live language switch), and only ever with the exact locale its own `useI18n()`
is rendering. It is not content negotiation and it does not claim to know what the person *wants* — it
states what the interface in front of them currently *is*. That distinction is why it sits **below**
the stored column rather than above or beside it: a preference a person recorded on purpose always
outranks a fact about the screen they happen to be looking at right now, on this device, in this tab.

### Decision 3 — nothing persists a guess; how this refines the nullable-column decision

`SetUserLocale::rendered()` only **reads** the header. No code path writes `X-Client-Locale`, or
anything derived from it, into `users.locale`. `PUT /api/user/locale` remains the only writer, and it
is driven exclusively by the request body — pinned by
`UserLocaleTest::test_the_client_header_does_not_write_the_column`, which sends a header claiming `pl`
and a body claiming `en`, then asserts the column holds `en`.

This is the same doctrine the nullable-column migration introduced three weeks earlier, applied one
layer further out. That migration's whole argument was that **`null` and `'en'` have to stay two
different facts** — "never asked" is not "chose English" — because a `default 'en'` column had been
silently converting every fresh signup into a recorded English preference no one had actually made.
This ADR **refines, and does not revert**, that decision: it adds a second source the middleware may
*read* (the header) without touching what the *column* is allowed to *mean*. A person who has never
opened the switcher still has a `null` column after this change, still follows the installation-versus-
client order fresh on every request, and still leaves nothing frozen into their account. Recording what
a browser or a screen currently renders as though it were a decision is exactly the trap the nullable
column exists to keep the app out of — extending that same trap to the header would have undone the
migration's own point three weeks after it shipped.

### Decision 4 — marked behaviour change: unauthenticated responses now follow the header

Login, password-reset, and invite-preview responses **now follow `X-Client-Locale` when present**,
where previously (even under the column-only design) they could only ever fall back to `APP_LOCALE`,
since there is no authenticated user to read a column from. This is deliberate and is the same fix as
the authenticated case, pointed at a different population: someone who has never logged in has
certainly never *chosen* a server-side locale either, so the language their login screen is currently
rendering is the only signal available, and it is honoured the same way a stored choice's absence is
honoured elsewhere in this design.

What this does **not** change: an unauthenticated request that carries no header at all (curl, a
health check, an integration with no `next` client in front of it) still gets `APP_LOCALE`, unmoved.
`Accept-Language` is still not read pre-auth either — `test_an_unauthenticated_request_uses_the_
application_locale` sends `Accept-Language: en-GB,en;q=0.9` against a Polish installation and still
asserts a Polish validation message back.

### Decision 5 — the header gets the exact-match guard, no softer than the column

`supportedOrNull()` is the single gate both the column and the header pass through:
`in_array($locale, self::supported(), true)`, strict, no normalization. No trimming, no case folding,
no `pl-PL` → `pl`. The header is treated as ordinary user input — a stale client, a proxy, or a `curl`
call can send anything — so it gets no softer a check than a column a past migration could have written
garbage into. `UserLocaleTest::test_an_unsupported_client_locale_changes_nothing` walks a table of
near-misses (`de`, `pl-PL`, `PL`, `' pl'`, `''`, `'knowledge.relation_types.member_of'`, `'../../etc'`)
and asserts every one of them changes nothing, falling through exactly as an unsupported column value
does.

**This guard is load-bearing beyond input hygiene.** `config('app.supported_locales')` values become a
path segment wherever the translator loads a language file (`lang/{locale}/...`), so a locale string
that reached `App::setLocale()` unvalidated would not merely mis-render a sentence — it would let
request-controlled user input select a filesystem path component. The exact-match requirement is not a
style preference sitting beside that fact; it is what keeps the header from ever becoming a path-
selection vector in the first place.

### Decision 6 — two invariants this design leans on, now load-bearing and stated nowhere else

Neither of the following is enforced by a test today. Both are true of the codebase **as it stands**,
and both are things a future change can break without this middleware itself changing at all — which is
exactly why they are recorded here rather than left implicit.

**Invariant A — no shared cache may hold `__()` output under a key that omits the locale.** This
middleware makes the *same endpoint* answer in different languages depending on a request header. That
is safe only as long as nothing caches a translated string under a key that does not vary by locale —
the moment it does, one request's `X-Client-Locale` populates the cache and every other locale's
request reads the wrong language back until the entry expires, with the request header itself as the
attacker- or accident-controlled trigger. **Today this is true by absence of the failure mode, not by a
guard against it**: the only two `Cache::remember` call sites in the codebase are
`App\Modules\Auth\Services\AuthContextCache::permissions()` (keyed `auth:perms:{user}:v{version}:
{workspace}`, caching a compiled **permission array** — no translated string) and
`App\Modules\Tasks\Repositories\TasksRepository::statusCounts()` (keyed per workspace, caching
**integer counts**). Neither holds `__()` output. The day a cache does — a translated label, a rendered
error message, anything that passes through the translator before being memoized — its key must include
the locale it was rendered in, or this header becomes a cache-poisoning vector for every other user
reading that entry afterward.

**Invariant B — outbound mail must resolve its locale from the recipient, not the request.** A queued
mailable is typically built and dispatched from within a request, and this middleware has, by the time
that happens, set the *request's* locale via `App::setLocale()` from whichever of the three sources won.
If a mailable's view called `__()` without first pinning its own locale, an invitation triggered by a
request whose `X-Client-Locale` happened to say `pl` would render in Polish for a recipient who never
expressed a language preference at all — the header would leak from "the screen the sender is looking
at" into "the language a third party receives," which the header was never meant to speak for. **Today
this is true by absence, not by a guard**: the only mail in the codebase,
`App\Modules\Workspaces\Mail\WorkspaceInvitationMail`, is hardcoded English prose with no `__()` call
anywhere in its `envelope()`/`content()` (`"You've been invited to join {$this->workspaceName} on
Taskio"`) — there is no vector because there is nothing translated to leak. **Before any mail in this
codebase is translated**, `App\Models\User` should implement
`Illuminate\Contracts\Translation\HasLocalePreference` (it does not today), and every mailable should
resolve its locale from the recipient it implements that contract for — `Mail::to($user)->send(...)`
already consults `HasLocalePreference` automatically once a model implements it — rather than from
whatever locale happens to be active on the sending request.

## Known limitation

`users.locale` carried `default 'en'` from the day the column was added until the nullable migration
landed, and that migration deliberately did **not** backfill — it could not tell a stamp a past
default had left from a preference a person had actually recorded, so it changed no existing row. The
practical consequence, verified directly against this installation: **every existing account holds
`'en'`** while `APP_LOCALE=pl` here, which is the exact state the original "server answers in English"
report came from. `X-Client-Locale` **does not rescue these accounts, by design** — Decision 1's whole
point is that a stored value is a choice nothing may second-guess, so honouring the header over an
existing `'en'` stamp would be the identical bug in miniature: overriding what looks like a decision
with a guess, just aimed the other way. `UserLocaleTest::test_a_stamped_english_row_still_answers_in_
english` pins exactly this — a forced `'en'` column stays `'en'` even against a header claiming `pl`.

These accounts are corrected only one of two ways: the person re-asserts the language in the switcher
— which now writes the column even when the clicked locale is already the active one, see
`resources/js/next/app/i18n/index.ts`'s `setLocale()` — or an owner-approved data decision resets the
stamped rows to `null`. This ADR does not make that data decision; it only removes the previous
obstacle (the early return that made clicking an already-lit locale segment do nothing at all).

## Open question

There is currently no way to **un-choose**. `PUT /api/user/locale` accepts only `en|pl`
(`UserLocaleTest::test_the_write_accepts_only_supported_locales`) and never `null` — once a column
holds a supported value, there is no UI or endpoint action that returns it to "never asked." The only
paths back to `null` today are the nullable migration's own effect on genuinely new rows, or a direct
database write. Whether the switcher should ever offer "follow my browser again" — and, if so, whether
that is a write of `null` or a fourth resolution source — is not decided here.

---

## Alternatives considered

- **Read `users.locale` alone, exactly as originally shipped.** Rejected — see Context: it leaves
  every account that has never touched the switcher (which, per the Known Limitation, is also every
  *stamped* account until corrected) with no locale opinion from the column, falling straight to
  `APP_LOCALE` and reproducing the reported defect for a different population.
- **Consult `Accept-Language`.** Rejected — see Decision 2. It answers the same endpoint differently
  for two people on the same screen, drags in q-values/region-subtag negotiation this codebase has
  never needed, and conflates a browser's guess with a client's own statement of fact.
- **Persist the header's value into `users.locale` the first time it is seen.** Rejected — see
  Decision 3. It would freeze a browser/screen default into the account as though it were a decision,
  which is precisely the failure mode the nullable-column migration exists to prevent — this ADR
  refines that migration's guarantee, it does not create a second way to defeat it.
- **Fold `pl-PL`/region subtags into their base language before matching.** Rejected — see Decision 5.
  That is the start of a content-negotiation layer this design deliberately does not have, and the
  guard doubles as the thing standing between request input and a path segment in the translation
  loader; softening it for convenience softens a security-relevant check for a case that never occurs
  in practice (the client sends exact catalog strings).
- **Leave unauthenticated responses on `APP_LOCALE` only, unchanged.** Rejected — see Decision 4. It
  is the identical defect restated for a different population: a visitor who has never logged in has
  certainly never made a server-side choice either, and the login screen is exactly where language
  mismatch is most visible (a Polish form refusing a password in English).

## Consequences

- The API group's every response now genuinely depends on `X-Client-Locale` when a request carries no
  effective stored choice — a caller other than the `next` client that wants correctly-localized
  responses (a future mobile client, an integration) must send the header itself or accept
  `APP_LOCALE`.
- The `web` group and Blade views are unaffected and stay pinned to `APP_LOCALE` —
  `test_the_locale_middleware_is_not_applied_to_the_web_group` — so `<html lang>` on the SPA shell and
  (once any exists) mail rendered during a web-group request cannot be steered by a request header.
- **Invariant A and Invariant B (Decision 6) are not enforced by any test today.** They hold only
  because of what the codebase currently does *not* do (no `__()` output in a shared cache key; no
  translated mail). The next `Cache::remember` that memoizes a translated string, or the first mailable
  that calls `__()`, must account for locale explicitly — this ADR is where that obligation is written
  down, since neither invariant is stated anywhere else.
- The **Known Limitation** is a real, present state of this installation's data, not a hypothetical:
  every existing row answers in English until a person re-asserts their language or an owner approves a
  bulk reset to `null`. No automatic correction is planned or implemented.
- The **Open question** (no un-choose path) is left open rather than answered by this ADR; a future
  change to `PUT /api/user/locale` accepting `null`, or a switcher affordance for it, should record its
  own reasoning rather than being folded into this one silently.

---

## Addendum (R4, password reset)

**Date:** 2026-09-12
**Found while building:** `App\Modules\Auth\Services\PasswordResetService` (commit `4a82d2e`)

Decision 6's **Invariant B** above named the risk in the abstract — "before any mail in this codebase
is translated, resolve its locale from the recipient, not the request" — and judged it *true by absence*
because the one existing mail, `WorkspaceInvitationMail`, calls no `__()` at all. Building the first
translated mail, the password-reset link, turned that absence into a concrete defect, caught by a test
before it shipped rather than after.

**The framework fact that makes this load-bearing, not stylistic.** `Illuminate\Foundation\Application::
setLocale()` does not only hand the locale to the translator — it **writes** `config('app.locale')`
first:

```php
// vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1596-1603
public function setLocale($locale)
{
    $this['config']->set('app.locale', $locale);
    $this['translator']->setLocale($locale);
    $this['events']->dispatch(new LocaleUpdated($locale));
}
```

`SetUserLocale::handle()` calls `App::setLocale()` on very nearly every `api`-group request (Decision 1's
three-step order — stored choice, else `X-Client-Locale`, else nothing). By the time **any** later code
in that request reads `config('app.locale')`, it is not reading "the installation's language when nobody
has an opinion" — it is reading **whichever locale won the middleware's resolution for this one caller**,
which for an unauthenticated request with no stored column is the `X-Client-Locale` header that same
caller sent. A "fallback to `config('app.locale')`" written anywhere downstream of the middleware was
therefore never a fallback to the installation default; it was a fallback to **the last caller's own
header**.

**Why this was an oracle for the reset mail specifically.** `POST /auth/forgot-password` is
unauthenticated and open to any address (`ForgotPasswordRequest::authorize()` returns `true` by design —
see the class docblock). The first draft of `PasswordResetService::mailLocale()` read `users.locale` and
fell back to `config('app.locale')` for an account that had never chosen. Because `SetUserLocale` had
already run against *that same request* before the service executed, the fallback resolved to whatever
`X-Client-Locale` the POSTer's own browser sent — letting a stranger who does not own the address choose
the language of a letter delivered to somebody else's inbox. This is exactly the leak Invariant B
predicted in the abstract, materializing on first contact with a real translated mailable, and it was
caught by `PasswordResetTest::test_reset_mail_language_ignores_the_requesting_clients_locale` (a request
carrying `X-Header: X-Client-Locale: pl` against a `null`-locale account, asserting the mail still renders
in `en`) before any mutation testing round began.

**The fix: a twin config key nothing mid-request rewrites.** `config/app.php` gained `default_locale`,
deliberately **not** `locale`:

```php
// config/app.php
'default_locale' => env('APP_LOCALE', 'en'),
```

Same env var as `app.locale`, read once at boot, and — unlike `app.locale` — never touched again by
`App::setLocale()`. The only sanctioned reader is a new static method, guarded through the same
`supported()` allow-list the column and the header already pass through:

```php
// App\Http\Middleware\SetUserLocale
public static function installationDefault(): string
{
    $default = config('app.default_locale');
    $supported = self::supported();

    return is_string($default) && in_array($default, $supported, true)
        ? $default
        : $supported[0];
}
```

The account-level half of the rule ("which language does THIS account read letters in") started life as
a private `PasswordResetService::mailLocale()`; when the THIRD letter arrived (D4, the failed-publication
mail) the rule was **extracted** to live beside its sibling rather than copied — so the entry point for
any code addressing a mail to an account is now:

```php
// App\Http\Middleware\SetUserLocale
public static function accountLocale(User $user): string
{
    $chosen = $user->locale;

    return is_string($chosen) && in_array($chosen, self::supported(), true)
        ? $chosen
        : self::installationDefault();
}
```

`installationDefault()` remains the inner half (and the direct call for code with no account in hand —
a console command, a third-party notice). `PasswordResetService` and the publication-failure listener
both delegate to `accountLocale()`; a second copy of this ternary anywhere is the drift this addendum
exists to forbid.

Pinned by `PasswordResetTest::test_reset_mail_is_written_in_the_language_the_account_chose`,
`::test_reset_mail_falls_back_to_the_app_locale_when_none_was_chosen` and
`::test_reset_mail_language_ignores_the_requesting_clients_locale` — all three set
`config(['app.default_locale' => 'en'])` **explicitly** rather than inheriting it, named for the same
reason the rest of this codebase's `.env`-sensitive tests are: `APP_LOCALE` is whatever the developer's
own `.env` says (`pl` on this installation), so a test that assumed a default would pass or fail
according to what somebody last switched on by hand.

**Verified for this addendum: no other reader exists yet.** A search of `app/` for
`config('app.locale')` / `config("app.locale")` turns up exactly two hits, and both are docblock
*warnings* against doing it, not reads — `SetUserLocale.php` itself (documenting why
`installationDefault()` exists) and `PasswordResetService.php` (documenting the trap its own first draft
fell into). Decision 6's Invariant B premise — "nothing today reads `app.locale` as an installation
default" — held everywhere except the one caller this very batch introduced, and that caller has since
been corrected.

**The rule this addendum adds, stated plainly for the next mailable or scheduled command:** code
addressing a mail to an ACCOUNT calls `SetUserLocale::accountLocale($user)`; code that wants "what does
this installation speak when nobody involved has an opinion" — a mail to a third party, a queued job
with no request context, a console command — calls `SetUserLocale::installationDefault()`. Neither may
**ever** read `config('app.locale')` for that purpose:
inside a request that key has already been overwritten by whichever locale `SetUserLocale` resolved for
*that* caller, and outside a request it is simply the boot-time value with no guarantee about who last
mutated it in-process. This sharpens Decision 6's Invariant B from "true by absence of a translated mail"
into an enforced rule with a named, tested escape hatch — the obligation Decision 6 said the next
translated mailable would have to account for has now been accounted for once, and this addendum is
where the next one should look first.
