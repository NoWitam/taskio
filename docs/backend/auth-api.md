# Backend API: Auth module

Module: `app/modules/Auth/`
Auth: `POST /auth/login`, `POST /auth/forgot-password` and `POST /auth/reset-password` are
**public** (`authorize(): true` on their FormRequests — there is nothing to authorize against, or
authorizing would itself leak information; see "Password reset" below). `POST /auth/logout` and
`GET /auth/me` require `auth:sanctum`. No endpoint in this module requires `X-Workspace-Id` —
`AuthService::context()` builds the workspace list the SPA needs from the authenticated user, it
does not read one.
Tenant scope: `User` and `password_reset_tokens` are both **central-only**
(`App\Traits\UsesCentralConnection` on `User`; the tenant migration set contains no `users` table
and no reset-token table at all — an account is not scoped to a workspace, so there is nothing for
an own-database tenant to hold).

Covers login/logout/me (unchanged, existing behaviour, documented here for the first time) and R4's
password-reset flow (`4a82d2e`, decision **D5**, "≤R4"): `POST /auth/forgot-password` and
`POST /auth/reset-password`, built on the framework's own password broker through its `$callback`
seam — no parallel token-lifecycle implementation. Also covers the SPA-side contract that makes
these screens reachable without a session (`meta.public`, `7f460a1`), because the two are one
feature: a reset link that a broken frontend guard bounces to `/login` is not a working reset link.

---

## Concepts

### Sessions are Sanctum personal access tokens, not framework sessions

`AuthService::issueToken()` mints a Sanctum PAT (`$user->createToken('api', ['*'], $expiresAt)`) —
30 days when `remember` is true, 1 day otherwise. There is no server-side session store to
additionally clear: `SESSION_DRIVER` is `file` and this installation has never run the `sessions`
table migration (still commented out), so **a Sanctum token is what "a session" means here**. Logout
(`AuthService::logout()`) resolves and deletes exactly the presented bearer token
(`PersonalAccessToken::findToken($bearerToken)?->delete()`) — not every token the user holds, unlike
a password reset (see "Revocation semantics" below).

### Password reset is the framework's broker, with only delivery and timing replaced

`App\Modules\Auth\Services\PasswordResetService` calls `Password::sendResetLink()` /
`Password::reset()` unmodified — the token's creation, its 60-minute expiry and its 60-second
per-address cooldown all come from `config('auth.passwords.users')`, and the store holds only a
bcrypt hash of the token (`password_reset_tokens.token`), never the value mailed out. Two things are
this module's own: the **mail** (a translated `Mailable` through the broker's `$callback`, replacing
the framework's stock notification) and the **timing** — see below.

### Anti-enumeration is a contract in bytes *and* in the clock

`POST /auth/forgot-password` returns the identical sentence, `200`, for a known address, an unknown
one, and a cooled-down one (`passwords.requested`, phrased conditionally — *"if an account exists for
that address..."* — so it stays true when nothing was actually sent). The broker's cooldown result
(`RESET_THROTTLED`) is folded into that same silence on purpose: it only ever fires for an address
that **exists** and asked recently, so surfacing it distinctly would leak both facts in one response.
`POST /auth/reset-password` collapses its two failure paths the same way — the broker checks the
*user* before the *token*, so an unknown email would answer `passwords.user` while a known one with a
bad token answers `passwords.token`; both become one `422` on the `token` field
(`PasswordResetService::reset()`), because a client that can tell the two apart has a membership
oracle on the second endpoint too.

**Equal bytes were not enough on their own — response time is a second channel.** A framework
`Timebox` is a *floor*, not an equalizer: it sleeps only the remainder after the work finishes, so it
pads a fast branch up to the floor and lets a slower one run past it. The branch that **finds** an
account does real work the branch that finds nothing does not — bcrypt-hashing the fresh token
measured **213-230 ms** on this codebase at `BCRYPT_ROUNDS=12`, against the framework broker's own
`200 ms` inner floor, before the mail is even rendered. A hit was therefore stably slower than a miss,
and the millisecond gap was the same oracle the identical sentence was built to prevent.

The fix is `PasswordResetService`'s own floor, read from `config('auth.passwords.timebox_ms')`
(default **1000 ms**), wrapped around *both* entry points with a fresh `Timebox` instance per call
(never shared with the broker's — `PasswordBroker::reset()` calls `returnEarly()` on its own timebox
on success, and sharing an instance would let that flag skip the outer padding on exactly the branch
whose duration is worth hiding). Measured after the fix: a hit ≈1001 ms, a miss ≈1001 ms, a rejected
reset ≈1003 ms.

**This floor is not a fire-and-forget constant.** The docblock on
`PasswordResetService::DEFAULT_TIMEBOX_MS` and the comment beside `auth.passwords.timebox_ms` both
say the same thing: **re-measure before raising `BCRYPT_ROUNDS` or pointing `MAIL_MAILER` at a remote
SMTP relay.** Either one adds real work to the branch that hits; if that work ever exceeds the
configured floor, the floor stops equalizing and the timing oracle is back — a timebox pads a fast
branch, it never trims a slow one. A remote-relay deployment that cannot otherwise stay under the
floor has one honest option: queue the mail, which removes the transport call from the measured
branch entirely (not done today — `sendMail()` calls `Mail::to(...)->send()` synchronously, matching
`WorkspaceInvitationMail`'s existing convention).

**The transaction is deliberately narrow.** `PasswordResetService::reset()`'s `DB::transaction()`
wraps only the password write, the credential rotation and the fresh session — not the whole
timeboxed call. An earlier draft wrapped the whole redemption; once the floor became a full second,
that meant every hostile request (this is a public, unauthenticated endpoint a script will hammer)
held a Postgres connection **idle in transaction** for up to a second doing nothing. The accepted cost
of the narrower scope: the broker's own delete of the spent token now runs just *after* the commit
rather than inside it — a failure in that one statement leaves a token technically redeemable a second
time, reachable only from the inbox that just received it, until the ordinary 60-minute expiry.

### Locale of the reset mail: the account's choice, never the requester's header

The reply on the screen and the letter in the inbox are answered by two different rules, and
conflating them was a real defect caught before it shipped — see the "Addendum (R4, password reset)"
at the end of
[`docs/decisions/ADR-0053-user-locale-header-fallback.md`](../decisions/ADR-0053-user-locale-header-fallback.md)
for the full account, including the framework fact (`Illuminate\Foundation\Application::setLocale()`
writes `config('app.locale')`) that made the first draft of this wrong. In short:

- The **HTTP response** (validation messages, the `passwords.requested` sentence) follows
  `SetUserLocale`'s ordinary order: stored choice, else `X-Client-Locale`, else the installation
  default — because it answers the screen that is asking, and anybody may POST any address.
- The **mail** follows only `users.locale`, falling back to `SetUserLocale::installationDefault()`
  (reads `config('app.default_locale')`, the twin key nothing mid-request rewrites) —
  **never** `config('app.locale')` and **never** the request's `X-Client-Locale`. Anybody may name
  any address on this endpoint; letting the request choose the mail's language would let a stranger
  pick the language of a letter delivered to the account holder.

Pinned by `PasswordResetTest::test_reset_mail_is_written_in_the_language_the_account_chose`,
`::test_reset_mail_falls_back_to_the_app_locale_when_none_was_chosen` and
`::test_reset_mail_language_ignores_the_requesting_clients_locale` (the last sends
`X-Client-Locale: pl` against a `null`-locale account and asserts the mail still renders in `en`).

### Throttle buckets — two different roles, kept in separate counters on purpose

`ThrottleRequests`'s third argument is a **key prefix**, and it is load-bearing: without one, every
throttled route an authenticated (or, here, unauthenticated-by-IP) caller touches shares **one**
counter.

| Route | Bucket | Role |
|---|---|---|
| `POST /auth/forgot-password` | `throttle:5,1,auth-password-forgot` | 5/min **per IP**. Stops one machine spraying many addresses. |
| `POST /auth/reset-password` | `throttle:10,1,auth-password-reset` | 10/min **per IP**. Kept in a *separate* bucket from the line above on purpose — a flood of link requests must not `429` the people redeeming links they already hold. |
| *(the broker's own)* `auth.passwords.users.throttle` | 60 s | Per **address**, not per IP — no amount of IP rotation gets around it. Stops many machines flooding one inbox with mail. |

The two halves compose: the route buckets are the *per-IP* defence, the broker's cooldown is the
*per-address* one, and neither alone is sufficient (a distributed sender defeats the first, a single
patient machine defeats the second on its own).

### Revocation semantics — what a successful reset ends, and what it deliberately does not

A completed reset does more than change a column, because after R4 an account holds **live OAuth
tokens to the company's real social channels** — losing control of the account is losing control of
what the company publishes, so the recovery path has to be able to end somebody else's access, not
merely restore the rightful owner's. `PasswordResetService::rotateCredentials()`, run inside the one
narrow transaction:

1. **The password** — assigned raw, hashed by `User`'s `hashed` cast (the same path
   `ProfileController::updatePassword` uses; hashing here again would double-hash).
2. **Every Sanctum personal access token this user holds**, `$user->tokens()->delete()` —
   every browser, every device, every "remember me" login, including an attacker's — deleted
   **before** the fresh token is minted, so "all but the new session" is true by construction, not by
   an id comparison that could be got wrong.
3. **`remember_token`**, rotated (`Str::random(60)`) — the SPA is bearer-token only, but the `web`
   guard and the column are real, and an old value would leave any remember cookie minted from it
   valid.
4. **The cached permission set** (`AuthContextCache::forget($user->id)`) — versioned out, so the next
   request compiles a fresh context rather than reading a stale one.

**Deliberately *not* touched: the workspace's stored platform credentials**
(`platform_connections` — see `docs/backend/publishing-api.md`). A password reset proves control of
an inbox; it is not, by itself, a reason to disconnect the company's YouTube or Meta account and break
every publication that connection has scheduled. Cutting an intruder out of the *account* is what this
flow does; revoking a *channel* stays a deliberate, separate act in Publishing, reachable by the
rightful owner once they are back in.

A stale `X-Workspace-Id` header from a previous session (still sitting in the browser's
`localStorage`) does not hide the target account on either endpoint: `ResolveWorkspace` no-ops
without an *authenticated* user, so `User`'s workspace-member scope never activates on these two
unauthenticated routes — pinned by
`PasswordResetTest::test_forgot_password_still_finds_a_user_outside_a_stale_workspace_header` and
`::test_reset_works_from_a_browser_carrying_a_stale_workspace_header`.

**Named, not built, in this batch:** `Settings`'s own change-password flow does *less* than a reset
(no token rotation, no cache-forget) — an intentional asymmetry, not a bug, since that flow is reached
only by someone already holding a live, authenticated session. Also named as a gap: no performance
regression test enforces the 1000 ms floor inside PHPUnit — the 213-230 ms/1001 ms/1003 ms measurements
above are from manual review at the time this batch shipped, not an automated timing assertion that
would catch the floor silently dropping below the cost of a real hit later.

---

## Endpoints

All under `/api/auth`, none behind `RequireWorkspace` (`app/modules/Auth/routes/api.php`).

### `POST /auth/login`

```http
POST /api/auth/login

{ "email": "person@example.com", "password": "correct horse", "remember": true }
```

`LoginRequest` requires `email` + `password`; `remember` is `sometimes|boolean`, defaulting to
`false`. Wrong credentials answer `422` on `email`
(`"The provided credentials are incorrect."` — deliberately generic, the same anti-enumeration
posture as the password-reset endpoints). No dedicated route-level throttle is registered on this
endpoint today (contrast the two password-reset routes above).

```json
{
  "token": "1|abcdef...",
  "user": { "id": "...", "name": "...", "email": "...", "avatar": null },
  "permissions": { "...": "..." },
  "workspaces": [
    { "id": "...", "name": "...", "db_mode": "shared", "status": "ready", "is_owner": true, "created_at": "..." }
  ],
  "current_workspace": "..."
}
```

`AuthService::login()` looks the user up with `User::withoutWorkspaceMemberScope()` — a defensive
bypass, not a security hole: login runs before any workspace context exists, so the member scope
would be inert anyway, and the explicit bypass is what stops a stray active-workspace context from
ever hiding a valid account and breaking authentication for it.

### `POST /auth/logout` — `auth:sanctum`

No body. `204` on success. Deletes the **presented** bearer token only (resolved by value via
`PersonalAccessToken::findToken()`, robust whether Sanctum authenticated via the token or a stateful
guard) and forgets the caller's cached permission set. Every other session of this user is
untouched — this is not the credential-revocation act a password reset is; see "Revocation semantics"
above.

### `GET /auth/me` — `auth:sanctum`

Returns the same shape `login` does, minus `token` (there is nothing new to hand back — the caller
already presented one). Reads the active workspace from the `X-Workspace-Id` header if present, else
defaults to the caller's first workspace. This is also the endpoint the SPA's router guard calls to
re-hydrate a session on boot (`auth.init()`) — see "SPA public routes" below for why it must never be
called on a public screen.

### `POST /auth/forgot-password` — public, throttled `5,1,auth-password-forgot`

```http
POST /api/auth/forgot-password

{ "email": "person@example.com" }
```

`ForgotPasswordRequest` checks `email` for **shape only** (`required|string|email|max:255`) —
deliberately no `exists:users` or any rule that would consult the table, which would turn a `422` into
an account-existence oracle in the one layer meant to prevent it. Always answers `200` with the
identical body regardless of outcome — see "Anti-enumeration" above:

```json
{ "message": "If an account exists for that address, we have sent it a password reset link." }
```

### `POST /auth/reset-password` — public, throttled `10,1,auth-password-reset`

```http
POST /api/auth/reset-password

{
  "token": "the-plaintext-token-from-the-mailed-link",
  "email": "person@example.com",
  "password": "a new password",
  "password_confirmation": "a new password"
}
```

`ResetPasswordRequest` requires all four fields; `password` follows the **same** rule the rest of the
app already enforces when a password is chosen — `min:8|confirmed`, matching
`Settings\UpdatePasswordRequest` and `Workspaces\AcceptInvitationRequest`. Possession of the mailed
token **is** the authorization; there is no Policy because there is no subject to check one against
before the broker verifies the hash.

Success returns the **login-shaped** payload — `message` + `token` + the same `user`/`permissions`/
`workspaces`/`current_workspace` context `login` returns — because the person just proved control of
the inbox and set the password, and every other session of theirs was just revoked; leaving them on a
login form after that would only delay the one thing they came to do:

```json
{
  "message": "Your password has been reset.",
  "token": "2|ghijkl...",
  "user": { "...": "..." },
  "permissions": { "...": "..." },
  "workspaces": [ "..." ],
  "current_workspace": "..."
}
```

An invalid, expired or already-used token — or an unknown email — both answer identically:

```json
{ "message": "...", "errors": { "token": ["This password reset token is invalid."] } }
```

Requires `php artisan migrate` — see "Deployment notes" below.

---

## SPA public routes — `meta.public` is load-bearing, not decoration

**This is a standing rule for every future pre-login screen, not just the two this batch added.** The
`next` router (`resources/js/next/app/router/index.ts`) marks a handful of route records
`meta: { public: true }` (`/login`, `/forgot-password`, `/reset-password`, `/invitations/:token`, plus
the styleguide gallery). Before `7f460a1` that flag was cosmetic — the guard bounced on
`meta.requiresAuth` alone, so a route missing `public` rendered exactly the same as one that had it,
and a route-wiring test asserted a comment that was no longer true. Two real defects followed from
that gap:

1. **The router guard used to hydrate a session on *every* navigation**, including a public one.
   `auth.init()` calls `GET /auth/me`, and on the reset screen a stale token in `localStorage` is the
   **expected** state — someone who came to reset a forgotten password almost certainly has a dead
   token sitting around. Waiting on that round-trip delayed a screen that needs no session at all for
   nothing.
2. **The api client's 401 interceptor threw the whole browser at `/next/login`** unconditionally
   (`window.location.assign('/login')`), and that `/auth/me` call from (1) reliably produced a 401.
   `/next/reset-password?token=&email=` carries a pair that arrives **exactly once**, by mail — a hard
   navigation discards it from the URL, stranding the person on a login form they came here *because*
   they could not use.

Both are fixed, and the fix is now what the flag is *for*: the guard (`router/index.ts`) skips
`auth.init()` for any matched route carrying `meta.public`, and the interceptor
(`app/lib/api.ts`) checks a **pathname list**, `PUBLIC_PATHS`
(`app/lib/publicRoutes.ts`), before redirecting on a 401 — never on a path the list covers.

**Why a pathname list exists at all, duplicating the router's own `meta.public`.** The interceptor
lives in `app/lib/api.ts`, and `app/router` imports the auth store, which imports the api client — a
router import from `lib/api` would close that cycle at module-evaluation time. `window.location.
pathname` is the one fact both sides can name without importing the other, and it is already what the
interceptor reads to avoid its own redirect loop. **The duplication is pinned, not merely
tolerated**: `app/lib/__tests__/publicRoute401.spec.ts` walks the real route records and fails if a
`meta.public` route is missing from `PUBLIC_PATHS`, or if an entry in the list turns out to belong to
a `requiresAuth` route — drift in *either* direction goes red.

**The rule for the next pre-login screen:** giving a route `meta: { public: true }` is necessary but
not sufficient — it must also be added to `PUBLIC_PATHS` in `app/lib/publicRoutes.ts`, or the drift
pin (`publicRoute401.spec.ts`) fails on the next test run, by design. A screen reachable before login
that forgets either half is exposed to the same two defects this batch closed.

**Named, not fixed, in this batch** (decisions deferred, not oversights): `main.ts` still calls
`auth.init()` unconditionally at boot regardless of the landing route (cutting that off on a public
route would break the invited-and-already-signed-in redirect that invitations relies on); a
theoretical race between the boot-time `/auth/me` and a reset that just completed is judged
practically excluded by the 1-second timebox floor above; and a hard navigation to `/login` while
already signed in no longer bounces to the dashboard (in-app navigation to it still does).

---

## Deployment notes

- **`php artisan migrate` is required before either password-reset endpoint works.**
  `password_reset_tokens` is a **new, central-only** table
  (`database/migrations/2026_09_12_000000_create_password_reset_tokens_table.php` — the stock
  Laravel skeleton table this installation had commented out since reset was deferred pre-R4). Until
  the migration runs, both endpoints fail on the missing table; there is no code-level fallback.
- **`MAIL_MAILER=log` in development writes the full reset link — a working, one-time credential —
  in plain text to `storage/logs/laravel.log`.** This is the `log` driver's own documented behaviour,
  not a defect in this module, but it has a real consequence on a development box that is reachable
  from a network: `APP_ENV=local` on such a machine exposes whoever's log file that is to anyone who
  can read it, for every account that has ever requested a reset.
- **`APP_URL` builds every mailed reset link.** `PasswordResetMail` composes the URL as
  `config('app.url') . '/next/reset-password?' . http_build_query([...])`
  (`app/modules/Auth/Mail/PasswordResetMail.php`) — a wrong or stale `APP_URL` in a given environment
  produces mail that points nowhere useful, silently, since nothing here validates the value.
- **The OAuth redirect host/`APP_URL` relationship is already documented for Publishing** — see
  `docs/decisions/ADR-0054-platform-connections-oauth.md` §8, "Deployment note:
  `publishing.oauth.redirect_base` must name the application's own host." The same class of mistake
  (a config value describing this application's own public origin drifting from the host actually
  serving it) applies to `APP_URL` here for the identical reason: both values end up inside a link
  handed to something outside this process — a platform's OAuth callback there, an inbox here.

---

## Related files

- `app/modules/Auth/` — module root
- `app/modules/Auth/Http/Controllers/AuthController.php` — `login`/`me`/`logout`/`forgotPassword`/
  `resetPassword`; the docblock on `forgotPassword` states its own must-not-branch invariant
- `app/modules/Auth/Services/AuthService.php` — login, token issuance, `/auth/me` context, logout
- `app/modules/Auth/Services/PasswordResetService.php` — the whole reset flow: broker delegation,
  the anti-enumeration message, the timebox, the narrow transaction, credential rotation, mail locale
- `app/modules/Auth/Mail/PasswordResetMail.php` — the translated reset mail, and the reset-link URL
- `app/modules/Auth/DTOs/ResetPasswordDTO.php` — the three reset values, carried as a DTO because they
  must not be silently reordered (unlike `forgotPassword`'s single scalar)
- `app/Http/Middleware/SetUserLocale.php` — locale resolution order; `installationDefault()` is the
  value the reset mail's fallback reads
- `config/auth.php` — broker config (`passwords.users`), plus `passwords.timebox_ms`
- `config/app.php` — `default_locale`, the twin of `locale` nothing mid-request rewrites
- `database/migrations/2026_09_12_000000_create_password_reset_tokens_table.php` — central-only,
  requires a manual `migrate`
- `resources/js/next/app/router/index.ts` — the guard's `meta.public` skip
- `resources/js/next/app/lib/publicRoutes.ts` — `PUBLIC_PATHS`, the interceptor's half of the contract
- `resources/js/next/app/lib/api.ts` — the 401 interceptor
- `resources/js/next/pages/auth/ForgotPasswordPage.vue`, `ResetPasswordPage.vue` — the two shell-less
  `next` screens
- `tests/Feature/PasswordResetTest.php` — every property described above, in one file
- `resources/js/next/app/lib/__tests__/publicRoute401.spec.ts`,
  `resources/js/next/pages/auth/__tests__/passwordResetRoutes.spec.ts` — the SPA-side pins
- `docs/decisions/ADR-0053-user-locale-header-fallback.md` (Addendum) — the `config('app.locale')`
  trap this batch found and fixed
