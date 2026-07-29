<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider & Model
    |--------------------------------------------------------------------------
    |
    | The provider and model used by the application's AI agents (approval
    | evaluation and bot task execution). Defaults preserve the previously
    | hard-coded values so behavior is unchanged; override via env if needed.
    |
    */

    'provider' => env('AI_PROVIDER', 'openai'),

    'model' => env('AI_MODEL', 'gpt-4o'),

    // HTTP-client timeout (seconds) for the Disk preview's AI image edits AND the generator's text→image
    // base generation. Both are long synchronous calls (tens of seconds); this bounds the provider request
    // itself — the webserver/php-fpm execution limits (and the generation job timeout) must be at least as
    // generous.
    'image_timeout' => (int) env('AI_IMAGE_TIMEOUT', 120),

    // The laravel/ai PROVIDER the generator's text→image BASE generation (ImageGenerateService) targets.
    // Passed EXPLICITLY so the call never falls back to laravel/ai's own `default_for_images` — the package
    // default there is 'gemini', which this app neither overrides nor holds a key for, so an implicit call
    // would fail. Defaults to the app-wide AI provider (env AI_PROVIDER, itself 'openai') so image generation
    // rides the SAME provider the rest of the app (bots/approvals/Disk edits) already uses; override with
    // AI_IMAGE_GENERATE_PROVIDER only to split it out. gpt-image-1.5 (OpenAI's default image model) accepts
    // the square 1:1 size + 'high' quality this service sends.
    'image_generate_provider' => env('AI_IMAGE_GENERATE_PROVIDER', env('AI_PROVIDER', 'openai')),

    // laravel/ai render `quality` ('low'|'medium'|'high') for the generator's text→image BASE generation
    // (ImageGenerateService). A social post wants a detailed result, so 'high' is the default. Distinct from
    // the Disk EDIT quality above (a different provider path — the custom masked OpenAI edit client).
    'image_generate_quality' => env('AI_IMAGE_GENERATE_QUALITY', 'high'),

    // Provider timeout (seconds) for the Disk preview's SYNC AI text edits. A gpt-4o text edit is
    // fast and runs inline in the request (no queue, unlike the image edit), so this bounds the call
    // the user is waiting on.
    'text_timeout' => (int) env('AI_TEXT_TIMEOUT', 60),

    // Provider timeout (seconds) for the generator's CREATIVE-DIRECTION derivation — the one small
    // structured call a full generation run makes before it renders anything. Deliberately TIGHTER than
    // text_timeout: it runs INSIDE RunGenerationSessionJob's fixed 300s SIGALRM window on top of the
    // ai-text fan-out, so bounding it at 30s is what lets that window stay untouched
    // (4 x 60 + 30 = 270s < 300s). A hung derivation fails closed and the run proceeds direction-less.
    'direction_timeout' => (int) env('AI_DIRECTION_TIMEOUT', 30),

    // The OpenAI model for Disk preview image edits. gpt-image-1 supports MASKED images/edits
    // (inpainting / object removal / background replace) — verified against the account. Kept
    // separate from the chat 'model' above.
    'disk_image_model' => env('AI_DISK_IMAGE_MODEL', 'gpt-image-1'),

    // gpt-image-1 `input_fidelity` for edits: 'high' preserves the source (unmasked area + context)
    // for a cleaner, better-blended masked edit — worth the extra input tokens for object removal.
    // Set '' (empty) to omit the parameter.
    'disk_image_input_fidelity' => env('AI_DISK_IMAGE_INPUT_FIDELITY', 'high'),

    // gpt-image-1 render `quality` for edits ('low'|'medium'|'high'|'auto'). Object removal / area
    // replace on complex scenes needs a detailed reconstruction, so 'high' is the default (medium —
    // the provider's own default — reconstructs a flat, washed-out patch). Set '' to omit.
    'disk_image_quality' => env('AI_DISK_IMAGE_QUALITY', 'high'),

    // gpt-image-1 `background` for edits ('opaque'|'transparent'|'auto'). Forced to 'opaque' so a
    // "remove the object" edit reconstructs SOLID background pixels instead of cutting a transparent
    // hole (the provider's 'auto' default can make the masked region transparent, which then shows
    // through as a washed patch when composited over the original). Set '' to omit.
    'disk_image_background' => env('AI_DISK_IMAGE_BACKGROUND', 'opaque'),

    // Soft per-workspace DAILY cap on Disk AI image edits (billed, slow provider calls). Counted at
    // DISPATCH now the edit is queued (so a pending edit already consumes the cap and the queue
    // cannot be flooded past it); 0 disables the cap. Complements the per-minute `disk-ai` bucket.
    'disk_image_max_per_day' => (int) env('AI_DISK_IMAGE_MAX_PER_DAY', 50),

    // Stale-edit reaper: seconds a queued/processing AI image edit may sit before it is considered
    // stuck (a worker killed mid-run — SIGKILL/OOM — never fires the job's failed() hook, and
    // nothing else would recover it). `disk:reap-stale-ai-edits` marks such edits failed. Must
    // EXCEED the job's whole retry budget (tries x image_timeout + backoff) so a slow-but-alive
    // retrying edit is never reaped — hence the same generous default as bot_run_timeout.
    'disk_image_edit_timeout' => (int) env('AI_DISK_IMAGE_EDIT_TIMEOUT', 900),

    // How long a terminal (done/failed) AI image edit is kept before the reaper prunes it. The row
    // holds a multi-MB base64 result, so retention is short — long enough for the browser's poll to
    // collect the image, not long enough to accumulate.
    'disk_image_edit_retention' => (int) env('AI_DISK_IMAGE_EDIT_RETENTION', 3600),

    /*
    |--------------------------------------------------------------------------
    | Bot Task Execution
    |--------------------------------------------------------------------------
    |
    | max_runs_per_task   Hard cap on how many times a bot may run against a single
    |                     task (initial run + every resume-after-reply + every
    |                     revision-after-reject all count). When hit, the bot hands
    |                     the task over to a human instead of advancing it.
    | context_comment_limit  How many of the most recent task comments are injected
    |                     into the bot's context (the conversation window).
    |
    */

    'max_runs_per_task' => (int) env('AI_MAX_RUNS_PER_TASK', 5),

    // Absolute ceiling on a task's total bot runs, INCLUDING human-initiated retries
    // (which are otherwise exempt from `max_runs_per_task`). Caps runaway AI spend from
    // repeated retry → fail → retry loops: past this, retry is refused.
    'max_runs_hard_cap' => (int) env('AI_MAX_RUNS_HARD_CAP', 20),

    // Stale-claim reaper: seconds a run may sit in `running` before it is considered
    // stuck (a worker killed mid-run never fires failed(), so the atomic claim would
    // never recover it). `bots:reap-stale-runs` releases such runs back to idle and
    // records a failure so the inbox surfaces them as retryable. Must exceed the longest
    // plausible real run so a slow-but-alive run is not reaped prematurely.
    'bot_run_timeout' => (int) env('AI_BOT_RUN_TIMEOUT', 900),

    'context_comment_limit' => (int) env('AI_CONTEXT_COMMENT_LIMIT', 30),

    // Cap on the total characters of the bot's knowledge module injected into the
    // execution context (so a large knowledge base can't blow the context window).
    'knowledge_max_chars' => (int) env('AI_KNOWLEDGE_MAX_CHARS', 8000),

    /*
    |--------------------------------------------------------------------------
    | Bot Tool Registry (B5)
    |--------------------------------------------------------------------------
    |
    | Optional tools a bot can be granted in its task-execution module.
    |
    | search.api_key   web_search is AVAILABLE only when this is filled. With no key
    |                  the tool is hidden in the UI and NEVER exposed to the agent.
    | fetch_max_bytes / fetch_timeout   fetch_url response/size caps (SSRF-guarded).
    | fetch_max_chars  cap on the plain-text returned to the agent.
    | generate_file_max_bytes / read_attachment_max_bytes   file tool size caps.
    |
    */

    'search' => [
        'provider' => env('AI_SEARCH_PROVIDER', 'brave'),
        'api_key' => env('AI_SEARCH_API_KEY'),
        'results' => (int) env('AI_SEARCH_RESULTS', 5),
    ],

    'fetch_timeout' => (int) env('AI_FETCH_TIMEOUT', 10),
    'fetch_max_bytes' => (int) env('AI_FETCH_MAX_BYTES', 2 * 1024 * 1024),
    'fetch_max_chars' => (int) env('AI_FETCH_MAX_CHARS', 20000),
    'fetch_max_redirects' => (int) env('AI_FETCH_MAX_REDIRECTS', 3),

    'generate_file_max_bytes' => (int) env('AI_GENERATE_FILE_MAX_BYTES', 1024 * 1024),
    'read_attachment_max_bytes' => (int) env('AI_READ_ATTACHMENT_MAX_BYTES', 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | AI Cost Meter (R2)
    |--------------------------------------------------------------------------
    |
    | The LEDGER meter (LedgerMeteredAiCall) records every AI spend and gates BEFORE spend on a
    | per-workspace rolling-month budget. Since R2 sub-stage 4 the gate is DOLLAR-based: it sums the
    | month's `estimated_cost` and refuses at/over the effective $ cap. `estimated_cost` is therefore
    | LOAD-BEARING now — surfaced to operators as an ESTIMATE (szacowany), never billed on. The
    | operator maintains the prices below; every figure is env-overridable.
    |
    | monthly_cost_cap_default  Effective $ budget when a workspace sets NO override
    |                      (workspaces.ai_monthly_cost_cap is null). 0 = DISABLED (the default), which
    |                      keeps existing workflow/disk behavior byte-preserved until an operator opts in.
    | pricing              Per-CHANNEL price model driving `estimated_cost`:
    |                        - ai_text          per_1k_tokens: $ per 1k REAL provider tokens (total_tokens).
    |                        - ai_image_edit     per_call: flat $ per image edit (images are priced per call,
    |                                            not per token — the 4000-token unit_cost below is NOT a price).
    |                        - ai_image_generate per_call: flat $ per generated image.
    |                      Defaults are representative current-provider ESTIMATES (gpt-4o blended text,
    |                      gpt-image-1 high-quality images); tune via env for the deployment's real prices.
    | monthly_token_cap    LEGACY token figure — retained for reference/telemetry only; the gate no longer
    |                      reads it (the $ cap above is the single source of truth). Kept env-overridable.
    | warn_ratio           Fraction of the $ cap at which a UI should warn (~80%).
    | unit_cost            SECONDARY token stand-in recorded for an OPAQUE result that carries no provider
    |                      token count (e.g. an image edit), keyed by channel — for the token DISPLAY only,
    |                      never the price basis (image $ come from pricing.<channel>.per_call).
    |
    */

    'meter' => [
        'monthly_cost_cap_default' => (float) env('AI_MONTHLY_COST_CAP', 0.0),
        'monthly_token_cap' => (int) env('AI_MONTHLY_TOKEN_CAP', 0),
        'warn_ratio' => (float) env('AI_METER_WARN_RATIO', 0.8),
        'pricing' => [
            'ai_text' => [
                'per_1k_tokens' => (float) env('AI_PRICE_TEXT_PER_1K', 0.005),
            ],
            'ai_image_edit' => [
                'per_call' => (float) env('AI_PRICE_IMAGE_EDIT_PER_CALL', 0.17),
            ],
            'ai_image_generate' => [
                'per_call' => (float) env('AI_PRICE_IMAGE_GENERATE_PER_CALL', 0.19),
            ],
        ],
        'unit_cost' => [
            'ai_image_edit' => (int) env('AI_IMAGE_EDIT_UNIT', 4000),
            'ai_image_generate' => (int) env('AI_IMAGE_GENERATE_UNIT', 4000),
        ],
    ],

];
