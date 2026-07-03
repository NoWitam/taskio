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
