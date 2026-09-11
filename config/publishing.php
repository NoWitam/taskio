<?php

use App\Modules\Publishing\OAuth\GoogleOAuthProvider;
use App\Modules\Publishing\OAuth\MetaOAuthProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth
    |--------------------------------------------------------------------------
    |
    | state_ttl  How long an authorization handshake may stay open, in seconds. It bounds the window in
    |            which a stolen `state` is worth anything, and it is short because the thing it covers is
    |            a person clicking through a consent screen — not a background job.
    |
    |            600 is generous for that: Google's consent screen with an account chooser and a
    |            two-factor prompt is a minute or two of real use, and ten minutes leaves room for the
    |            user who reads the permission list. Longer would widen the replay window for no benefit
    |            anybody would notice.
    |
    | state_ledger_grace  Extra seconds the SINGLE-USE ledger entry outlives the signed payload's own
    |            expiry. It exists so "expired" and "already used" stay DISTINGUISHABLE: the payload is
    |            checked first and answers `state_expired`; a ledger miss on a still-valid payload can
    |            then only mean the nonce was consumed. Without the grace the two would race and a
    |            second click would sometimes report the wrong reason.
    |
    | redirect_base  The public origin the platforms redirect BACK to, e.g. https://taskio.example.com.
    |            NULL means "derive it from APP_URL", which is right in every environment except the one
    |            that matters first: Meta and Google both require an HTTPS redirect URI registered on
    |            their side, and `http://localhost` is not one. The owner's day-0 track puts a public
    |            HTTPS origin in front of this app; this is where its origin goes, so the callback URL
    |            we hand the platform matches the one registered with them EXACTLY — a mismatch is
    |            rejected by the platform with an error nobody can act on from our side.
    |
    |            IT IS COUPLED TO THE COOKIE HOST, and the symptom of getting that wrong points at the
    |            wrong file. The handshake binding (`OAuthStateService::HANDSHAKE_COOKIE`) is a HOST-ONLY
    |            cookie: it is set on the host that served the authorize XHR and is sent back only to
    |            THAT host. So the host in this value must be the host the application is served from —
    |            or one covered by `SESSION_DOMAIN`. Point it at a different host (a separate callback
    |            subdomain, an origin in front of a tunnel, `www.` on one side and not the other) and the
    |            cookie never arrives: EVERY legitimate connect fails as `oauth_browser_mismatch`, which
    |            reads as a defect in the state service rather than as a URL somebody typed.
    |
    | return_path  Where the browser lands after the handshake, with the outcome in the query string.
    |            The SPA route does not exist yet (B2 is backend-only); until the frontend adds it, a
    |            real connect ends on the SPA's not-found screen having SUCCEEDED. Stated here rather
    |            than discovered there.
    |
    */

    'oauth' => [

        'state_ttl' => (int) env('PUBLISHING_OAUTH_STATE_TTL', 600),

        'state_ledger_grace' => (int) env('PUBLISHING_OAUTH_STATE_LEDGER_GRACE', 120),

        'redirect_base' => env('PUBLISHING_OAUTH_REDIRECT_BASE'),

        'return_path' => env('PUBLISHING_OAUTH_RETURN_PATH', '/next/publishing/connections'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Token lifecycle
    |--------------------------------------------------------------------------
    |
    | refresh_lead  How far AHEAD of `expires_at` a token is refreshed, in seconds. Refreshing at the
    |            moment of expiry is refreshing too late: the sweep runs on a cron, the platform's clock
    |            is not ours, and a token that expires between two passes takes a scheduled post with it.
    |
    |            86400 (a day) is chosen against the SHORTEST real lifetime in play. A Meta long-lived
    |            token is about sixty days, a Google access token about an hour — and the Google one is
    |            not covered by this sweep in any meaningful way, because it is refreshed on demand long
    |            before an hourly pass would notice. What the lead really protects is the sixty-day
    |            token: a full day of passes to get it renewed before anything is at stake.
    |
    | refresh_batch  Ceiling on how many connections ONE pass will attempt, per database. A bound on
    |            WORK, not on correctness — whatever is left is picked up by the next pass, because the
    |            selection is "expiring soonest first" and a refreshed row leaves the set.
    |
    |            PER DATABASE, WHICH IN SHARED MODE IS EVERY WORKSPACE AT ONCE — not per workspace. An
    |            own-database workspace gets its own pass and its own 200; the shared database gets one
    |            pass and one 200 covering the whole common estate. That is safe today rather than by
    |            luck: the order is `expires_at` ascending and the lead is a full day, so a pass that hits
    |            the cap defers exactly the connections with the most time left, and twenty-four passes
    |            run before any of them is at risk. It stops being safe if the shared estate ever grows
    |            past ~200 connections expiring inside the same day — raise this, or shorten the pass
    |            interval, before that happens.
    |
    */

    'tokens' => [

        'refresh_lead' => (int) env('PUBLISHING_TOKEN_REFRESH_LEAD', 86400),

        'refresh_batch' => (int) env('PUBLISHING_TOKEN_REFRESH_BATCH', 200),

    ],

    /*
    |--------------------------------------------------------------------------
    | The queue (B3)
    |--------------------------------------------------------------------------
    |
    | THE ONE INVARIANT ON THIS PAGE, AND EVERY NUMBER BELOW IS ORDERED BY IT:
    |
    |     publish_timeout  <  the queue connection's retry_after  <<  stale_after
    |
    | publish_timeout  The SIGALRM on one `PublishPublicationJob`, in seconds. It must stay BELOW the
    |            queue connection's `retry_after` (90 by default — `config/queue.php`), and that ordering
    |            is load-bearing rather than tidy. Above it, the SAME payload is redelivered while the
    |            original is still talking to a platform; the job carries `tries = 1`, so the duplicate is
    |            failed BEFORE any middleware runs and its `failed()` hook fires against a LIVE publish.
    |
    |            Nothing is CORRUPTED by that: every status write is a conditional update carrying the
    |            status it decided from, so the redelivery's park and the live publish's own conclusion
    |            cannot both land, and both sides handle losing. What the ordering buys is WHICH of them
    |            wins — park first, and a publication that went out perfectly well waits in
    |            `needs_reconcile` for a person who has nothing useful to do. So keep the alarm inside the
    |            redelivery window. 60 is generous for two HTTP calls to a platform; raise it only
    |            together with `retry_after`.
    |
    | dispatch_batch  Ceiling on how many due publications ONE pass claims, PER DATABASE — which in
    |            shared mode is every shared workspace at once, exactly as `tokens.refresh_batch` is. A
    |            bound on WORK, not on correctness: the order is `scheduled_at` ascending, a claimed row
    |            leaves the selection, and the sweep runs every minute, so an overflow is published a
    |            minute late rather than not at all. It is deliberately generous — a publication that is
    |            LATE is a promise broken quietly, which is the failure mode this module can least afford.
    |
    | stale_after  How long a row may sit in `publishing` before the reaper concludes that whoever
    |            claimed it is gone, in seconds. MUCH larger than `publish_timeout` on purpose: the
    |            reaper's whole subject is the death a timeout could not catch (SIGKILL, OOM, a worker
    |            restart), and reaping a publish that is merely slow would park a row whose platform call
    |            is still in flight. 900 is fifteen times the alarm — far past any live call, far short of
    |            leaving a stranded row invisible for a working day.
    |
    | reap_batch  Ceiling on how many stranded rows ONE reaper pass parks, per database — the same bound
    |            on WORK that `dispatch_batch` is, and it exists for the same reason a bound always does
    |            here: the pass has no upper limit of its own, and the one event that produces many
    |            stranded rows at once is a worker host dying with a full queue. The order is
    |            `last_attempt_at` ascending (nulls first), so an overflowing pass defers the FRESHEST
    |            strandings — the ones most likely to still be alive — and a parked row leaves the
    |            selection, so a backlog drains over consecutive five-minute passes.
    |
    | reconcile_batch  How many publications one automatic reconciliation pass will PROBE, per database.
    |            A probe is a read-only question to a platform, and read-only is not free: it is an API
    |            call against somebody's rate limit, made on behalf of a row that may never resolve.
    |
    | reconcile_cooldown  The shortest interval between two AUTOMATIC probes of the same publication, in
    |            seconds. `needs_reconcile` has no limit on attempts and no escalation — by design, it is
    |            the state with no automatic exit — so without a cooldown a row nobody ever fixes would be
    |            asked about every five minutes forever, which is 288 calls a day per stuck row and
    |            exactly the traffic a platform holds against the whole application. An hour is short
    |            enough that a crashed worker's publication resolves itself while somebody is still at
    |            their desk. The cooldown is kept in the CACHE, not on the row: losing it costs one extra
    |            read-only question, and that is the correct direction for this particular mechanism to be
    |            wrong in. The manual endpoint ignores it entirely — a person asking is not a sweep.
    |
    */

    'queue' => [

        'publish_timeout' => (int) env('PUBLISHING_PUBLISH_TIMEOUT', 60),

        'dispatch_batch' => (int) env('PUBLISHING_DISPATCH_BATCH', 200),

        'stale_after' => (int) env('PUBLISHING_STALE_AFTER', 900),

        'reap_batch' => (int) env('PUBLISHING_REAP_BATCH', 200),

        'reconcile_batch' => (int) env('PUBLISHING_RECONCILE_BATCH', 100),

        'reconcile_cooldown' => (int) env('PUBLISHING_RECONCILE_COOLDOWN', 3600),

    ],

    /*
    |--------------------------------------------------------------------------
    | Platforms
    |--------------------------------------------------------------------------
    |
    | ONE SECTION PER DESTINATION, and the per-platform DIFFERENCES LIVE HERE rather than in a branch
    | inside a service. That is the whole arrangement: `driver` names the class that speaks a platform
    | family's dialect, and everything that varies between two members of the same family (endpoints,
    | scopes, the extra parameters a consent screen needs) is data on this page.
    |
    | THE TWO DIALECTS, AND WHY THEY CANNOT BE ONE
    |
    |   google  Issues a REFRESH TOKEN, but only on the first consent, and only when the authorize call
    |           carries `access_type=offline` AND `prompt=consent`. Leave either out and the exchange
    |           succeeds, hands back an access token good for an hour, and NO refresh token — a
    |           connection that works perfectly for sixty minutes and then needs a human. That is why
    |           those two parameters are in the config as data rather than as something a developer has
    |           to remember: they are load-bearing, and their absence fails LATER.
    |
    |           A refresh returns a new access token and NO new refresh token. The stored one is kept.
    |
    |   meta    Issues NO refresh token at all. A code is exchanged for a SHORT-lived token, which is
    |           then exchanged AGAIN (`grant_type=fb_exchange_token`) for a long-lived one — two calls
    |           for what Google does in one. "Refreshing" is that same second call made with the CURRENT
    |           long-lived token, so the credential a refresh consumes is the ACCESS token, not a
    |           separate one.
    |
    | That last difference is the reason `OAuthProvider::refresh()` is handed the whole credential pair
    | rather than a refresh-token string: one family renews with one field and the other with the other,
    | and a signature that only admitted a refresh token would have forced exactly the `if` this
    | arrangement exists to avoid.
    |
    | CREDENTIALS COME FROM ENV AND NOTHING ELSE. Null client ids are the shipped state: the applications
    | do not exist yet (the owner's day-0 track registers them), and every provider refuses to build an
    | authorize URL without them rather than sending a user to a consent screen that will reject them.
    |
    | The `dry_run` destination is deliberately ABSENT. It publishes nothing, has no account behind it
    | and needs no connection — asking to authorize it is refused rather than quietly served.
    |
    */

    'platforms' => [

        'youtube' => [
            'driver' => GoogleOAuthProvider::class,

            'client_id' => env('PUBLISHING_YOUTUBE_CLIENT_ID'),
            'client_secret' => env('PUBLISHING_YOUTUBE_CLIENT_SECRET'),

            'authorize_url' => env('PUBLISHING_GOOGLE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_url' => env('PUBLISHING_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),

            // The channel this token can act as. `mine=true` answers for the authorizing account, which
            // is the only account we are entitled to name, and `part=snippet` is what carries its title.
            //
            // THE PARAMETERS ARE A LIST, NOT A QUERY STRING ON THE URL. Both are load-bearing — Google
            // answers 400 `missingRequiredParameter` without `part` — and an endpoint whose query lives
            // in the URL is one an HTTP client can silently REPLACE: `PendingRequest::get($url, $query)`
            // hands Guzzle a `query` option, and Guzzle overwrites rather than merges. Kept as data,
            // they are merged deliberately by the provider and visible to whoever reads this file.
            'account_url' => env(
                'PUBLISHING_YOUTUBE_ACCOUNT_URL',
                'https://www.googleapis.com/youtube/v3/channels',
            ),

            'account_params' => [
                'part' => 'snippet',
                'mine' => 'true',
            ],

            'scopes' => [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ],

            // LOAD-BEARING. Without both of these Google issues no refresh token — see the dialect note.
            'authorize_params' => [
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ],
        ],

        'facebook' => [
            'driver' => MetaOAuthProvider::class,

            'client_id' => env('PUBLISHING_FACEBOOK_CLIENT_ID'),
            'client_secret' => env('PUBLISHING_FACEBOOK_CLIENT_SECRET'),

            'authorize_url' => env('PUBLISHING_META_AUTHORIZE_URL', 'https://www.facebook.com/v21.0/dialog/oauth'),
            'token_url' => env('PUBLISHING_META_TOKEN_URL', 'https://graph.facebook.com/v21.0/oauth/access_token'),
            // The SECOND call. Same endpoint, different grant — named separately so the two-step nature
            // of a Meta exchange is visible in the configuration rather than only in the code.
            'long_lived_url' => env('PUBLISHING_META_TOKEN_URL', 'https://graph.facebook.com/v21.0/oauth/access_token'),

            // See the YouTube section for why the fields are a list rather than a query string. Meta
            // happens to default `/me` to `id,name`, which is why this platform survived the defect that
            // broke YouTube — asking explicitly is what makes the response shape ours rather than theirs.
            'account_url' => env('PUBLISHING_META_ACCOUNT_URL', 'https://graph.facebook.com/v21.0/me'),

            'account_params' => [
                'fields' => 'id,name',
            ],

            'scopes' => [
                'pages_show_list',
                'pages_manage_posts',
                'pages_read_engagement',
            ],

            'authorize_params' => [],
        ],

        'instagram' => [
            'driver' => MetaOAuthProvider::class,

            'client_id' => env('PUBLISHING_INSTAGRAM_CLIENT_ID'),
            'client_secret' => env('PUBLISHING_INSTAGRAM_CLIENT_SECRET'),

            'authorize_url' => env('PUBLISHING_META_AUTHORIZE_URL', 'https://www.facebook.com/v21.0/dialog/oauth'),
            'token_url' => env('PUBLISHING_META_TOKEN_URL', 'https://graph.facebook.com/v21.0/oauth/access_token'),
            'long_lived_url' => env('PUBLISHING_META_TOKEN_URL', 'https://graph.facebook.com/v21.0/oauth/access_token'),

            'account_url' => env('PUBLISHING_META_ACCOUNT_URL', 'https://graph.facebook.com/v21.0/me'),

            'account_params' => [
                'fields' => 'id,name',
            ],

            // Publishing to Instagram goes through the linked Facebook Page, which is why the Page
            // scopes are here too. Getting this list wrong is not a code defect — it is a re-review.
            'scopes' => [
                'instagram_basic',
                'instagram_content_publish',
                'pages_show_list',
                'pages_read_engagement',
            ],

            'authorize_params' => [],
        ],

    ],

];
