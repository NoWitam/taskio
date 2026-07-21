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

    // HTTP-client timeout (seconds) for the Disk preview's AI image edits. Image generation is a
    // long synchronous call (tens of seconds); this bounds the provider request itself — the
    // webserver/php-fpm execution limits must be at least as generous.
    'image_timeout' => (int) env('AI_IMAGE_TIMEOUT', 120),

    // Provider timeout (seconds) for the Disk preview's SYNC AI text edits. A gpt-4o text edit is
    // fast and runs inline in the request (no queue, unlike the image edit), so this bounds the call
    // the user is waiting on.
    'text_timeout' => (int) env('AI_TEXT_TIMEOUT', 60),

    // The OpenAI model for Disk preview image edits. gpt-image-1 supports MASKED images/edits
    // (inpainting / object removal / background replace) — verified against the account. Kept
    // separate from the chat 'model' above.
    'disk_image_model' => env('AI_DISK_IMAGE_MODEL', 'gpt-image-2'),

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

];
