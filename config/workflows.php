<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow Run Budget (cost proxy)
    |--------------------------------------------------------------------------
    |
    | A workflow RUN is the unit of automated cost: each run may create tasks,
    | assign bots (which spend AI), start approvals, etc. Rather than meter each
    | of those separately, the number of runs stands in as the cost proxy (same
    | doctrine as config/ai.php's per-task run cap). The dispatcher reads
    | these when deciding whether a trigger may start a new run.
    |
    | max_runs_per_month  Soft budget: runs a single workflow may start per
    |                     calendar month before it is throttled. Counted by
    |                     WorkflowRunManager::runsThisMonth().
    | max_runs_hard_cap   Absolute WORKSPACE-WIDE monthly ceiling across ALL
    |                     workflows, INCLUDING manual runs — caps runaway spend
    |                     even when per-workflow soft budgets are bypassed.
    |                     Counted by WorkflowRunManager::runsThisMonthAcrossWorkspace().
    |
    */

    'max_runs_per_month' => (int) env('WORKFLOWS_MAX_RUNS_PER_MONTH', 100),

    'max_runs_hard_cap' => (int) env('WORKFLOWS_MAX_RUNS_HARD_CAP', 500),

    /*
    |--------------------------------------------------------------------------
    | Stale-claim reaper
    |--------------------------------------------------------------------------
    |
    | Seconds a run may sit in `running` before it is considered stuck. A worker
    | killed mid-run (SIGKILL/OOM) never fires the job's failed() hook, and the
    | atomic claim only matches `pending`, so nothing else could recover it.
    | `workflows:reap-stale-runs` releases such runs to `failed` with a timeout
    | error. Must exceed the longest plausible real run so a slow-but-alive run
    | is not reaped prematurely.
    |
    */

    'run_timeout' => (int) env('WORKFLOWS_RUN_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Re-trigger depth guard
    |--------------------------------------------------------------------------
    |
    | A workflow step can author a change (create a task, finish an approval)
    | that itself matches another workflow's trigger. `max_depth` bounds that
    | chain so a workflow that re-triggers itself cannot loop unbounded. The run
    | carries its `depth`; the dispatcher refuses to start a child run
    | beyond this depth.
    |
    */

    'max_depth' => (int) env('WORKFLOWS_MAX_DEPTH', 3),

    /*
    |--------------------------------------------------------------------------
    | AI Schedule Assist (B5)
    |--------------------------------------------------------------------------
    |
    | The natural-language -> structured-schedule assistant (POST /workflows/
    | schedule-assist) runs an LLM per request. Unlike a workflow RUN it creates
    | no tasks/approvals, so it is NOT metered against the run budget above; it is
    | throttled separately, per user, purely to bound AI spend on repeated calls.
    |
    | assist_rate_per_minute  Max schedule-assist prompts a single user may make
    |                         per rolling minute before a 429. Keyed by user id.
    |                         NOTE: the throttle lives in the DEFAULT cache store —
    |                         it requires a persistent store (production default
    |                         `database` is fine); under `array` it lasts only one
    |                         process, which is why tests can exercise it in-proc.
    |
    */

    'assist_rate_per_minute' => (int) env('WORKFLOWS_ASSIST_RATE_PER_MINUTE', 5),

    /*
    |--------------------------------------------------------------------------
    | AI Text directive (SB2)
    |--------------------------------------------------------------------------
    |
    | An `@[ai-text]` directive in a step's text field generates a piece of text
    | through an LLM at RUN time (one AI call per directive occurrence). Since the
    | RUN is already the metered cost unit, these are not throttled per user; they
    | are bounded PER RUN instead so a single run cannot fan out unbounded spend.
    |
    | ai_text_max_calls_per_run  Hard cap on ai-text AI calls within ONE run.
    |                            Occurrences beyond the cap resolve to '' (empty).
    | ai_text_max_chars          Length cap applied to each generated string. The
    |                            target field's own DB limit still applies on top
    |                            (a task title is a short column) — keep title
    |                            prompts concise.
    |
    */

    'ai_text_max_calls_per_run' => (int) env('WORKFLOWS_AI_TEXT_MAX_CALLS_PER_RUN', 10),

    'ai_text_max_chars' => (int) env('WORKFLOWS_AI_TEXT_MAX_CHARS', 2000),

];
