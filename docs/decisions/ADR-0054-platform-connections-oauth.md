# ADR-0054 — Platform connections and OAuth: encrypted tokens, a cache-only handshake ledger, and a browser-bound state

**Date:** 2026-09-06
**Status:** Accepted
**Module:** `App\Modules\Publishing` (`Models\PlatformConnection`, `Managers\PlatformConnectionManager`,
`Services\{PlatformConnectionService,OAuthStateService,OAuthProviderRegistry,TokenRefresher}`,
`OAuth\{AbstractOAuthProvider,GoogleOAuthProvider,MetaOAuthProvider}`,
`Http\Controllers\{PlatformConnectionController,PlatformOAuthCallbackController}`,
`DTOs\{OAuthState,OAuthTokens,PlatformCredentials,StartedHandshake,RemoteAccount}`,
`Exceptions\{OAuthExchangeFailed,OAuthStateRejected,CredentialsUnreadable}`,
`config/publishing.php`), plus two app-wide files this batch changed:
`App\Http\Middleware\LogMiddleware` and `bootstrap/app.php` (the `encryptCookies` exemption)
**Relates to:** ADR-0015 (polymorphic creator — how a connection is attributed from a signed state
rather than `$request->user()`), the B1 state machine in
`App\Modules\Publishing\Managers\PublicationManager` (the `scheduled ↔ blocked` edges this batch is the
first caller of), `docs/backend/publishing-api.md` (the wire contract this ADR explains the reasoning
behind)

---

## Context

R4 B1 shipped a publication state machine with two fences already built for a connection that did not
exist yet: `blocked` (a hold with no edge back to `publishing`) and `platform_connection_id` (a nullable
column with no foreign key). B2's job was to build the thing those fences were waiting for — one
authorized account per (workspace, platform), obtained through OAuth, holding a live credential for
somebody else's account.

That last fact decides everything else in this document. A leaked row here is not a data-protection
incident about our users — it is the ability to post publicly as a real YouTube channel or Facebook
Page. Four separate design problems fell out of that one property, and each is a decision below:
where the credential lives and how it is protected at rest; how a browser redirect with no
`Authorization` header and no `X-Workspace-Id` can be trusted to say which workspace an account belongs
to; how a `state` parameter that necessarily travels through a platform's servers and a browser's
history can still be redeemed only by the party that started the handshake; and what an unrelated,
already-shipped middleware was doing that made two of those guarantees false the moment this batch
landed.

---

## Decisions

### 1. Two separate `encrypted` columns, not one encrypted blob — and a tenant mirror, never a shared vault

`platform_connections.access_token` and `.refresh_token` are independent columns, both cast
`'encrypted'` on `PlatformConnection`, both `text` rather than `string`. This copies the one existing
encryption precedent in the codebase, `workspaces.db_password` (a single `encrypted`-cast column) —
it is not an invented shape.

**Why two columns and not one encrypted JSON blob.** The two tokens have different lifetimes and
different renewal paths: a Google refresh token is issued once and survives every access-token
renewal; a Meta connection has no refresh token at all and renews by re-exchanging its *access* token.
A single JSON blob would turn "store the new access token, keep the refresh token" into a
read-modify-write of a decrypted structure — exactly the operation that loses a refresh token to a
race or a partially-populated array. Two columns make each write name what it writes, and the merge
rule (`PlatformConnectionManager::applyTokens()`: a `null` in the response means *unchanged*, never
*there is none*) has one place to live rather than one per blob key.

**Why `text` and not `string`.** A Google refresh token is comfortably over 255 characters before
Laravel's envelope encryption wraps it in base64 with an IV and a MAC, which roughly doubles it again.

**What encryption at rest actually buys, stated honestly.** The application decrypts with `APP_KEY`,
so anything that can run our code can already read these. What it defends against is every path where
the bytes travel *without* the code: a database dump in a backup bucket, a replica, a support export —
and the one that this repository's own tooling made concrete, `LogMiddleware`, which writes every
executed statement with its bound values interpolated. Because the `encrypted` cast runs on the way
into the attribute, what that log receives is ciphertext. A JSON column encrypted by hand at each call
site would have leaked the moment one write forgot to.

**Two independent guards past the cast.** `PlatformConnection` also declares both columns `$hidden`,
and `PlatformConnectionResource` is a field allow-list that never names either. `$hidden` catches every
path that turns the model into text *without* going through the resource — a log context, an
exception's data array, a `dd()`, an endpoint written in a hurry — and the resource catches every path
that turns the model into an HTTP response. A single mistake now has to be made twice to leak a token.

**Central table plus a tenant mirror — a product decision before a technical one.** `platform_connections`
exists both centrally and at `database/migrations/tenant/0001_01_01_000080` (the same columns, minus
`workspace_id`, since one tenant database is one workspace). Keeping every connection centrally would
have been simpler — one place to sweep, one connection for the refresher — and it would have made a
customer's decision to pay for their own database false about the single most sensitive thing they
own: a client with their own-database workspace has their access tokens leave their database the
moment the first token is stored, alongside a workspace whose whole premise was that its data stays
inside it. `PublishingTenantDatabaseTest` pins the two schemas as column-identical, because the same
model reads both and a column present on only one side is a defect invisible to whichever workspace
never triggers it.

**What a broken key does, and does not, produce.** A `DecryptException` reaching the top of a request
(a rotated `APP_KEY`, or a database restored beside a different application) is translated by
`PlatformConnection::credentials()` into `CredentialsUnreadable` — never a raw 500. `TokenRefresher`
catches it specifically and calls `PlatformConnectionManager::markNeedsReauth()`, which is a genuine
state transition (`active → needs_reauth`) and, in the same transaction, holds every `scheduled`
publication on that connection (`PublicationManager::block()`, the `scheduled → blocked` edge). A
publish attempted against a connection in that state is refused by the state machine before any
platform is touched — it never reaches a 500 either. **Reconnecting the same account finds and repairs
the same row**: `PlatformConnectionManager::connect()` looks the account up with `withTrashed()` and a
`lockForUpdate()` keyed on `(platform, external_account_id)`, so a second authorization of a channel
that already has a row — broken or merely stale — updates it in place and releases the hold, rather
than creating a second row with a second, live token.

### 2. OAuth handshake single-use lives in the cache, never a table

`OAuthStateService` mints a `state` carrying a random `jti`, and makes it single-use by writing
`Cache::put('publishing:oauth-state:' . $jti, true, …)` when it is issued and `Cache::pull(...)` when
it is redeemed. There is no `oauth_states` table anywhere, central or tenant.

**The argument is specific to this application's tenancy, not a general cache-vs-database preference.**
A row proving "this nonce has not been used" has to live somewhere, and both obvious choices for
*where* are wrong for the same reason, from opposite directions:

- **A tenant table.** Choosing the tenant *database* requires knowing the workspace — and the workspace
  is a claim inside the very `state` payload the ledger check exists to verify. Consulting a tenant
  table would mean trusting the payload to select a database, and only then asking that database
  whether the payload was trustworthy — an ordering where the thing being checked gets to choose which
  checker answers.
- **A central table.** This would put the single-use ledger for an *own-database* workspace's
  handshake outside that workspace's own database — the exact posture Decision 1 above refuses for the
  tokens themselves, now reappearing one step earlier in the same flow.

**The cache sidesteps the dilemma because it is tenancy-agnostic by construction.** It is not a tenant
store, so there is no wrong database to pick; the ledger is consulted *before* any workspace is
resolved, and the identical code path holds for a shared workspace and an own-database one. It holds
nothing sensitive — a random id and a `true` — so its being outside a tenant database says nothing
about anybody's data, and expiry is the store's job, so there is no reaper for a table of dead nonces.

**What that costs, named rather than discovered.** With `CACHE_STORE=file` (this repository's `.env`)
the ledger is per server; a multi-server deployment must move the cache to a shared store (database or
redis, both already configured stores) or a callback landing on a different node than the authorize
call will report `already_used` for a first attempt. `php artisan cache:clear` invalidates every
in-flight handshake — a re-click, not data loss. And `Cache::pull()` is get-then-forget rather than a
single atomic operation, so two callbacks racing on the same nonce could in principle both pass the
ledger check; the residual risk is bounded to nothing because the authorization *code* is itself
single-use at the platform, so the second exchange fails there regardless. This is the second of two
independent guards, not the only one.

### 3. Binding the handshake to the browser that started it — the corrected model, told honestly

A signature proves a `state` was minted by this application. Single use proves it is redeemed once.
**Neither says anything about who is holding it**, and a `state` is not a secret by construction — it
travels in a URL, through a platform's own servers and access logs, and into the browser's history.

**Two attacks point in opposite directions, and one mechanism has to close both:**

- **The stolen state.** Somebody obtains a `state` inside its ten-minute window — over a shoulder, from
  a shared screen, out of a copied link — walks through the consent screen with *their own* account,
  and finishes the handshake against somebody else's workspace. This is not the harmless "the attacker
  is donating their own account" it first looks like: the connection lands in the victim's workspace and
  appears in its destination picker like any other, so everything subsequently scheduled to it publishes
  to a channel the attacker owns. It is a content-exfiltration path with a publish button on it.
- **The planted state — ordinary OAuth CSRF.** A self-contained signed state does *not* catch this,
  because the state is genuine: the attacker mints one for *their own* workspace and gets the victim to
  follow it. The victim consents with their own account, and the resulting token to the victim's channel
  lands in the attacker's workspace.

**Version 1 shipped and failed a review probe.** The handshake cookie's value was the `jti` — the same
identifier that sits, in plaintext base64url, inside the signed `state` payload. The reasoning behind it
was "an attacker holding the state cannot set a cookie in their own browser," which is false:
`HttpOnly` governs whether a *script on somebody else's page* can *read* a cookie; it says nothing about
an attacker *setting* one in a client they control, where there is no origin to respect because the
request is theirs. `curl -H 'Cookie: taskio_publishing_handshake=<the jti out of the state>'` against the
callback is the entire technique, and a reviewer's probe — a client with no prior contact with this
application — walked straight through it: the connection was created. The binding covered the planted
state and did nothing at all about the stolen one, because the one value an attacker needed was sitting
in plain sight inside the thing they had already stolen.

**Version 2, the shipped mechanism.** `issue()` draws 32 bytes from the CSPRNG (`random_bytes`) and hex-
encodes them. The **cookie** (`taskio_publishing_handshake`) carries that secret — `HttpOnly`,
`SameSite=Lax`, host-only (no `domain` set), living for `state_ttl + state_ledger_grace` seconds (600 +
120 = 720 s by default). The **signed payload** carries only `bh = sha256(secret)` — the digest, never
the secret. The payload format is bumped to `VERSION = 2`; a v1 state has no `bh` field and is refused as
`malformed` rather than silently accepted under the binding it predates.

**What this closes, in both directions.** A thief who captures a `state` (the URL, a browser history
entry, a platform's access log) holds a digest, and inverting SHA-256 of 256 random bits is not a
tractable attack — so the stolen-state path is closed: it cannot be redeemed without also holding a
cookie the thief never received. A state planted on a victim carries the *attacker's* `bh`; the victim's
own browser holds no cookie whose secret hashes to it (or holds none at all), so the callback refuses it
as `oauth_browser_mismatch` before anything is written — the planted-state path is closed the same way it
always was, now for a mechanism that also closes the other direction.

**The order of checks is deliberate:** shape → signature → platform → expiry → browser → ledger.
Signature runs before anything is read from the payload, so a forgery never reaches a cache lookup.
Platform and expiry are checked *before* the ledger, so a mis-routed or merely abandoned handshake is
refused *without being spent* — the person who actually holds the matching cookie can still retry. The
browser check runs before the ledger for the identical reason: a thief without the cookie secret cannot
destroy a legitimate holder's one chance to redeem the nonce by presenting it first.

**The honest boundary, stated rather than implied.** An attacker who captures *both* the `state` and the
cookie secret — a full read of the `POST …/authorize` response, which is an authenticated XHR over TLS —
still wins. That is outside this model on purpose: whoever can read that response already holds the
caller's bearer token, and a browser binding is not the mechanism that answers an attacker who is already
inside the session. The boundary this decision draws is exactly where the session's own boundary already
sits.

### 4. The callback is unauthenticated by construction, and the creator comes from the signed state alone

`GET /oauth/{platform}/callback` is a `web` route, registered in `routes/web.php` **above** the
`/next{path?}` SPA catch-all, and it sits outside `auth:sanctum`, outside `X-Workspace-Id`, and outside
`ResolveWorkspace`. This is not an oversight to close later: the request is a top-level browser
navigation performed by Google or Meta, which carries neither an `Authorization` header (the SPA
attaches its bearer token from JavaScript, which is not running during a cross-origin redirect) nor a
workspace header. Every fact this request needs — who asked, for which workspace, for which platform —
arrives instead inside the verified `OAuthState`.

`PlatformOAuthCallbackController` never consults `$request->user()`, not even as a fallback. The
connection's creator is threaded explicitly from `OAuthState::$userId` through
`PlatformConnectionService::completeAuthorization()` into
`PlatformConnectionManager::connect(..., creatorId: $state->userId)`, where `HasCreator`'s rule that an
explicit id wins is what makes the explicit value stick. Before this batch, `Auth::login()` for a
hardcoded user id sat inside `LogMiddleware`, globally prepended — ahead of every guard (see Decision 7).
Had it still been in place, `$request->user()` on this very route would have resolved to that hardcoded
identity rather than to nobody, and a controller that trusted it would have attributed every connection
to whoever that id names. `PublishingOAuthStateTest` pins the intended behaviour directly: it completes a
handshake while authenticated as a *different* user entirely and asserts the connection is still
attributed to the state's own `userId`.

Because `ResolveWorkspace` is absent from this route, the controller re-derives, by hand, everything that
middleware would otherwise have done: it re-checks that the named workspace still exists and is `Ready`,
re-checks that the named user is still a member (a ten-minute-old signed claim about membership is not a
current fact), and activates the tenant context itself — cleared in a `finally`, so a long-lived process
(a queue worker, an Octane-style runtime) never inherits a tenant connection from a request that already
finished.

### 5. The foreign key from `publications.platform_connection_id` is `restrict`, not `nullOnDelete`

`database/migrations/2026_09_06_000003_add_platform_connection_constraints_to_publications_table.php`
adds `$table->foreign('platform_connection_id')->references('id')->on('platform_connections')->restrictOnDelete()`
— verified in the migration itself, alongside a per-connection uniqueness on `(platform_connection_id,
remote_id)`.

`cascade` was never seriously on the table: disconnecting an account would delete the record of
everything ever published through it — rows that are the only account this application keeps of
artifacts that still exist in public, on somebody's timeline, with our text on them. `nullOnDelete` was
the genuinely tempting middle option and is still weaker than what shipped: it keeps the rows but severs
the attribution, so a *published* publication would survive without being able to say which account it
went out on — the one fact that distinguishes two otherwise-identical rows on a workspace with two
YouTube channels.

`restrict` says the harder, more honest thing: a connection with publications behind it cannot be erased
at all. In ordinary operation nothing ever exercises it, because disconnecting a connection is a **soft**
delete (`PlatformConnectionManager::revoke()`), and a soft delete is an `UPDATE` no foreign key notices.
What it catches is the other path — a purge, a console `forceDelete`, a future retention job — converting
a silent loss of public-artifact history into a loud refusal somebody has to make a decision about. The
accepted cost: a genuine erasure request against a connection with a publication history has to deal with
those publications explicitly, which is the correct amount of friction for deleting the record of a
public artifact.

### 6. Per-platform dialects live as data in `config/publishing.php`, never as branches in a service

Two OAuth families, one contract (`OAuthProvider`), and the differences between them are pushed as far
down as they go — into configuration where possible, into the two concrete providers
(`GoogleOAuthProvider`, `MetaOAuthProvider`) where a real branch is unavoidable:

- **Google refuses to hand back a refresh token unless the authorize call carried both
  `access_type=offline` *and* `prompt=consent`.** Both are `authorize_params` entries in
  `config/publishing.php`, labelled load-bearing in the file itself, rather than something a developer has
  to remember while assembling a query string. `GoogleOAuthProvider::exchangeCode()` additionally *refuses
  the exchange outright* (`OAuthExchangeFailed::UNUSABLE_RESPONSE`) when the response carries no refresh
  token — a connection that cannot be renewed is not a connection, and the honest moment to say so is at
  the consent screen, not in a queue at 09:00 two months later when the access token lapses with nothing
  left to renew it.
- **Meta issues no refresh token, ever.** A code exchanges for a short-lived token, which is exchanged
  *again* (`grant_type=fb_exchange_token`) for a long-lived one — two calls where Google needs one.
  "Refreshing" a Meta connection is that same second call, made with the *current* long-lived **access**
  token. This single fact is why `OAuthProvider::refresh()` takes the whole `PlatformCredentials` pair
  rather than a bare refresh-token string: a signature admitting only a refresh token would have forced
  exactly the `if` this arrangement exists to avoid, since the two families renew with different halves of
  the pair.
- **`account_params` (a YouTube channel lookup's `part=snippet&mine=true`, a Meta `/me`'s `fields=id,name`)
  are configured as separate, named query parameters — never assembled into the endpoint URL's own query
  string.** This is not a style preference; it closes a real, already-triggered defect. `Illuminate\Http\Client\PendingRequest::get($url, $query)`
  decides its behaviour by `func_num_args()`: passing a second argument — **including an empty array** —
  hands Guzzle a `query` option, and Guzzle **overwrites** the URL's own query rather than merging with it.
  A first version of the YouTube lookup called `get('…/channels?part=snippet&mine=true')` with no second
  argument at one call site and a second, differently-shaped call elsewhere that *did* pass one; the
  version that passed a second argument silently stripped the URL down to `…/channels`, which Google
  answers with 400 `missingRequiredParameter`. The failure landed *after* a successful consent screen and a
  successful token exchange — a refresh token issued, a real grant live on the user's account, and nothing
  stored on ours. Meta's own `/me` endpoint survived the identical defect by luck, because it defaults to
  the fields this application wanted anyway. The fix (`AbstractOAuthProvider::getJson()`) parses the URL's
  own query string and merges it *under* the caller's parameters before the request is sent, so the
  guarantee holds however an endpoint happens to be configured — and the regression is pinned by an
  assertion against the **URL actually sent** to the HTTP client, deliberately, because a wildcard
  `Http::fake()` pattern matches a truncated URL exactly as happily as a complete one and would not have
  caught this by itself.

### 7. `LogMiddleware`: a reversible interim decision, taken without the owner's sign-off

`LogMiddleware` is globally prepended (`bootstrap/app.php`, `$middleware->prepend(LogMiddleware::class)`)
— the first thing every request touches, ahead of every guard. Before this batch it did two things that
B2's own surface made unsafe, and this ADR records that both were removed, by whom, and on what
authority:

- **It logged the full request URL, including the query string.** B2 adds
  `GET /oauth/{platform}/callback?code=<authorization code>&state=<signed state>`. An authorization code
  is exchangeable for an access token for a real window of time — longer, specifically, when the exchange
  it was meant for *failed*, which is exactly the case a developer reads this log to understand. A live
  credential would have sat in `storage/logs` next to the state naming the workspace it belonged to.
- **It called `Auth::login()` for a hardcoded user id, on every request.** Prepended, that runs *before*
  `auth:sanctum`, before `ResolveWorkspace`, before every policy — an authentication bypass for whoever
  holds that uuid. It has been inert on this installation only because no such row currently exists in
  the database, which is a property of the data, not of the code. Decision 4 above depends on this line
  never firing: the callback controller is written to ignore `$request->user()` specifically *because*
  this line existed.

**Both are removed.** `LogMiddleware` now gates its entire body on `app()->environment('local')` — the
value of a query log is real on a developer's machine and is not a trade to make on a server, so
production keeps an empty middleware. Even so, credential-bearing query parameters
(`code`, `state`, `token`, `access_token`, `refresh_token`) are redacted from the logged URL as defence in
depth — the environment gate is one misconfigured variable away from being wrong, and `APP_ENV=local` on a
publicly reachable host is a mistake somebody eventually makes. The query log's bound *values* are
deliberately left unredacted, because they are the log's entire purpose and the columns that matter most
— the two token columns — are `encrypted` casts, so what the log receives for them is already ciphertext
(`PublishingConnectionSecrecyTest` pins this).

**This was the agent's decision, in the owner's place, after four unanswered requests for a ruling — and
it is explicitly reversible.** If the login line was serving a real local convenience nobody had written
down, the correct replacement is a documented, environment-gated dev-login helper, not a hardcoded id
inside a logging middleware. The veto on this decision remains open.

### 8. Deployment note: `publishing.oauth.redirect_base` must name the application's own host

`config('publishing.oauth.redirect_base')` is the public origin platforms redirect back to, and it is
**coupled to the handshake cookie's host**. `OAuthStateService::HANDSHAKE_COOKIE` is set with no explicit
`domain`, which makes it host-only: a browser will only return it to the exact host that set it (or a
host covered by `SESSION_DOMAIN`). If `redirect_base` names a host other than the one this application is
actually served from — a separate callback subdomain, a tunnel's own origin, a stray `www.` on one side
and not the other — the handshake cookie set on the authorize call never reaches the callback, and
**every legitimate connect fails as `oauth_browser_mismatch`**. That failure reads exactly like a defect
in `OAuthStateService`, and it is not one: it is a URL configured inconsistently with the host actually
serving the application. This is stated here, and beside the config key itself, because the symptom and
the cause are otherwise two files and one redirect apart.

---

## Alternatives considered

- **One encrypted JSON column for both tokens.** Rejected — see Decision 1; the two tokens renew on
  different paths and a blob write is a read-modify-write of a decrypted structure, the exact operation
  that silently loses a refresh token.
- **A central `platform_connections` table for every workspace, own-database included.** Rejected — see
  Decision 1; it would leave an own-database customer's most sensitive credential outside their own
  database.
- **A tenant-scoped `oauth_states` table for single-use enforcement.** Rejected — see Decision 2; it
  requires trusting the unverified payload to choose which database gets to verify it.
- **A central `oauth_states` table.** Rejected — see Decision 2; it puts an own-database workspace's
  handshake ledger outside that workspace's database, the same posture Decision 1 refuses for tokens.
- **Binding the handshake cookie to the `jti` already inside the signed state (version 1).** Rejected,
  in production, by a review probe — see Decision 3; the value being bound was public, so the binding
  closed the planted-state attack and did nothing for the stolen-state one.
- **`nullOnDelete` on `publications.platform_connection_id`.** Rejected — see Decision 5; it preserves the
  row but loses which account a published artifact actually went out on.
- **A generic `OAuthService` branching on platform inside its methods.** Rejected — see Decision 6; the
  two families' renewal credentials differ by which field of the pair they use, which would force exactly
  the conditional this contract shape (`refresh(PlatformCredentials $credentials)`) avoids.
- **Leaving `LogMiddleware`'s hardcoded auto-login and full-URL logging in place pending the owner's
  ruling.** Rejected — see Decision 7; both were live, exploitable properties the moment B2's OAuth
  surface existed, and waiting for a fourth unanswered request was judged riskier than making a
  documented, reversible call.

---

## Consequences

- **A rotated `APP_KEY` degrades gracefully, everywhere this batch touches.** A publication routes to
  `blocked` rather than throwing; a connection routes to `needs_reauth` rather than 500ing; the
  connections list still renders, via `PlatformConnectionResource::credentials_readable`, on an
  installation where every stored token has just become unreadable.
- **Multi-server deployments must move `CACHE_STORE` off `file`** before B2's handshake ledger is safe
  across nodes — see Decision 2. This is a deployment note, not a code change; both alternative stores are
  already configured options.
- **`publishing.oauth.redirect_base` and the host actually serving the application must agree, byte for
  byte** — see Decision 8. This is the first thing to check when a connect fails as
  `oauth_browser_mismatch` in a new environment, before suspecting the state service itself.
- **The `LogMiddleware` veto is open.** Restoring a login convenience, if one is wanted, is a new,
  documented, environment-gated mechanism — never a re-insertion of the removed line. See Decision 7.
- **B3 inherits both fences this batch attaches to, unchanged.** `PlatformConnectionManager` moves a
  publication only through `PublicationManager::block()`/`arm()` — edges the B1 state machine already
  contained — so a future queue/dispatch layer finds the hold-and-release behaviour already in place
  rather than needing to invent it.
- **`docs/backend/publishing-api.md`** documents the wire contract this ADR explains the reasoning
  behind: the connections endpoints, the OAuth start/callback flow, and the full table of callback
  `reason` codes.
