<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Generation Sessions — live @[ai-text] execution (R2 sub-stage 2b)
    |--------------------------------------------------------------------------
    |
    | A generation session renders a Template recipe's TEXT parts through the shared
    | @[ai-text] generator (GeneratorAiTextService → the Variables AiTextGenerationService,
    | metered + budgeted). These bound its per-session AI spend.
    |
    | ai_text_max_chars              Length cap on ONE generated post part. Posts are longer
    |                                than a workflow field (workflows.ai_text_max_chars = 2000),
    |                                so the ceiling is higher. The field's own limits still apply.
    | ai_text_max_calls_per_session  Soft ceiling on ai-text provider calls in ONE session run,
    |                                so a many-part recipe can't fan out into unbounded spend.
    |                                Instance-counted per run (like the Workflows decorator).
    |
    | TIMEOUT INVARIANT: a single run may make up to ai_text_max_calls_per_session provider calls (a
    | text part can carry SEVERAL inline @[ai-text] blocks), each up to ai.text_timeout (60s), plus —
    | since the creative-direction layer — exactly ONE derivation call bounded by the tighter
    | ai.direction_timeout (30s). So the text worst case is (max_calls x ai.text_timeout) + direction,
    | and it must stay UNDER RunGenerationSessionJob::$timeout (300s, INVIOLATE): otherwise a
    | slow-but-alive legit run is SIGALRM-killed and (tries=1) spuriously marked failed. Hence the
    | default 4: 4 x 60 + 30 = 270s < 300s (30s headroom for tenancy/DB/resolver work). Full ordering,
    | mirroring the Disk edit job: queue retry_after > job timeout > max_calls x ai.text_timeout +
    | direction (retry_after is left at the app default below the timeout; the job's WithoutOverlapping
    | lock makes a duplicate delivery safe regardless). Raise BOTH this budget AND the job timeout
    | together if a recipe genuinely needs a larger fan-out.
    |
    */

    'ai_text_max_chars' => (int) env('GENERATOR_AI_TEXT_MAX_CHARS', 5000),

    'ai_text_max_calls_per_session' => (int) env('GENERATOR_AI_TEXT_MAX_CALLS_PER_SESSION', 4),

    /*
    |--------------------------------------------------------------------------
    | Image chain executor (R2 sub-stage 2c)
    |--------------------------------------------------------------------------
    |
    | The server-side image chain (base resolve → pixel ops → ai_edit) runs SYNCHRONOUSLY inside
    | RunGenerationSessionJob. Produced images are always PNG.
    |
    | image_max_edge   The working image is capped to this many pixels on its LONGEST edge BEFORE the
    |                  chain runs (never upscaled; 0 disables). Bounds the cost of the pixel ops and the
    |                  bytes sent to a provider, and mirrors the Disk editor's export cap.
    |
    | image_edit_max_calls_per_session
    |                  Soft ceiling on `ai_edit` provider calls in ONE session run — the image parallel of
    |                  ai_text_max_calls_per_session. Instance-counted per run in ImageChainExecutor (resets
    |                  each run, like the ai-text decorator's $calls) and CUMULATIVE across every image part
    |                  of the run (a multi-scene video plan — and a storyboard's per-shot chain — shares the
    |                  same counter). When a CHAIN would exceed it the over-budget `ai_edit` step is SKIPPED
    |                  and the chain CONTINUES: the part still produces a real image, just WITHOUT that filter
    |                  (graceful degradation, logged, `image_status:'ok'`). It is deliberately NOT a part
    |                  failure: an authored storyboard filter chain applies to EVERY shot, so failing the part
    |                  would destroy the tail of the shot list over a decorative filter. A REFINE (editImage)
    |                  keeps the hard stop — the edit IS the whole op there, so it fails soft as a no-op with
    |                  `generator.sessions.image_budget` and the current image is preserved. Either way no
    |                  extra provider call is made, so a runaway recipe never becomes a whole-run timeout that
    |                  bills the completed edits first.
    |                  Its default is in LOCK-STEP with the ceiling (8/8) for the SAME reason as the generate
    |                  budget: ONE authored `ai_edit` filter x a full 8-shot storyboard = 8 edits, and a lower
    |                  budget would silently ship the last frames unfiltered (a visibly inconsistent set).
    |
    | image_generate_max_calls_per_session (R2 sub-stage 6)
    |                  Soft ceiling on `ai_generate` (text→image BASE) provider calls in ONE session run — the
    |                  base-generation sibling of image_edit_max_calls_per_session. Instance-counted per run in
    |                  ImageChainExecutor and CUMULATIVE across every image part of the run. A plain image part
    |                  has at most ONE base, BUT the video_script STORYBOARD (Phase B) fans a `ai_generate` base
    |                  out PER SHOT — up to `storyboard_max_shots` in one run — so this budget MUST accommodate a
    |                  full storyboard: its default is kept in LOCK-STEP with the platform ceiling (8/8) so a
    |                  full storyboard renders end-to-end. KEEP THEM COUPLED: if this budget is lower than the
    |                  ceiling, the last shots of a long list come back frameless (a listed beat with no image)
    |                  — the FE then shows imageless shots, which reads as a bug, not as a budget. The
    |                  over-budget generate FAILS THAT PART/shot soft
    |                  (`generator.sessions.image_generate_budget`) — other parts/shots still run, the session
    |                  ends `ready`. This does NOT loosen `post_with_image` (its single image part still does 1
    |                  generate; parts are registry-fixed — an author cannot add image parts). The REAL fan-out
    |                  bound for video_script is `storyboard_max_shots` (below); this budget just admits it.
    |
    | THREE-WAY COUPLING (storyboard_max_shots <-> generate budget <-> edit budget). A storyboard fans BOTH image
    | budgets out PER SHOT: each shot costs exactly ONE `ai_generate` base, plus ONE `ai_edit` for EVERY authored
    | `ai_edit` filter in the storyboard's SHARED chain (the same chain runs on every shot). So a full run needs
    | at least `ceiling` generates and `ceiling x (authored ai_edit filters)` edits from ONE cumulative counter.
    | All three defaults are therefore 8/8/8: a full 8-shot storyboard with the common SINGLE `ai_edit` filter
    | completes end-to-end. The two budgets fail DIFFERENTLY when short, which is why only one of them is a hard
    | bound: an exhausted GENERATE budget leaves a shot with no base at all → that shot is `failed` (frameless);
    | an exhausted EDIT budget only skips a filter → the shot is still `ok`, just unfiltered (see above). Keep
    | them coupled anyway — a set where the last frames silently lost the author's look is still a bad set. A
    | recipe with SEVERAL `ai_edit` filters per shot exceeds the edit budget by design and degrades gracefully;
    | raise the edit budget (and mind the timeout invariant) if that look must survive a full storyboard.
    |
    | TIMEOUT INVARIANT (extends the ai_text note above): an image ai_edit AND an ai_generate base are SLOW
    | synchronous provider calls (up to ai.image_timeout, default 120s each) and a plan may chain SEVERAL, so
    | the image fan-out (generate bases + edit filters) adds to the run's worst-case wall time on TOP of the
    | ai_text fan-out, and the WHOLE run (the one direction call + text calls + image generates + image edits)
    | shares the ONE RunGenerationSessionJob::$timeout (300s). The pessimal ceiling sum
    | (text_budget x ai.text_timeout + ai.direction_timeout + (image_generate_budget + image_edit_budget) x
    | ai.image_timeout = 4 x 60 + 30 + (8 + 8) x 120 = 2190s) exceeds
    | the job timeout ON PURPOSE: the ai.*_timeout values are per-call HUNG-PROVIDER ceilings (a healthy
    | call returns in a few seconds), NOT expected runtimes — a run that genuinely hits several full ceilings
    | is an already-degraded provider that would fail regardless. These CAPS' job is not to make that pessimal
    | case fit; it is to convert an UNBOUNDED author fan-out (a recipe listing 20 `ai_edit`/`ai_generate`
    | parts, OR a runaway shot list) into a bounded outcome — a clean per-part/per-shot soft failure for a
    | missing base, a skipped filter for an exhausted edit budget. The job timeout is
    | deliberately NOT raised — raising it would widen the WithoutOverlapping lock and the 2d stale-reaper
    | window the 2b hardening pinned. The STORYBOARD fan-out is bounded by `storyboard_max_shots` (8), so a
    | full single-filter storyboard is 8 generates + 8 edits that a healthy provider returns in ~5s each
    | (16 x ~5s ≪ 300s) — the HUNG-PROVIDER ceiling math above stays pessimal, not expected. Raising the
    | ceiling is what actually moves the EXPECTED wall time, so if a recipe genuinely needs a larger fan-out
    | at the ceiling, raise BOTH these budgets AND the job timeout together.
    |
    */

    'image_max_edge' => (int) env('GENERATOR_IMAGE_MAX_EDGE', 2048),

    // LOCK-STEP with storyboard_max_shots (8) so ONE authored `ai_edit` filter can run on EVERY shot of a FULL
    // storyboard (the executor holds ONE cumulative counter for the whole run); a plain single-image post still
    // needs just one. Move the three (ceiling / generate / edit) together — see the THREE-WAY COUPLING above.
    'image_edit_max_calls_per_session' => (int) env('GENERATOR_IMAGE_EDIT_MAX_CALLS_PER_SESSION', 8),

    // LOCK-STEP with storyboard_max_shots (8) so a FULL video_script storyboard (one ai_generate per shot)
    // renders in one run; a plain single-image post still needs just one. Move the two together.
    'image_generate_max_calls_per_session' => (int) env('GENERATOR_IMAGE_GENERATE_MAX_CALLS_PER_SESSION', 8),

    /*
    |--------------------------------------------------------------------------
    | Storyboard shot ceiling (video_script rework Phase B; adaptive since the direction layer)
    |--------------------------------------------------------------------------
    |
    | storyboard_max_shots  The PLATFORM CEILING on shots in one video_script run — the REAL cost bound. A
    |                       template MAY tighten it per recipe (`content.storyboard.max_shots`, validated at
    |                       write against this ceiling); the run's EFFECTIVE cap is min(authored, ceiling) and
    |                       is resolved ONCE by GenerationSessionExecutor::effectiveShotCap, then threaded
    |                       explicitly into BOTH the shot-list side (the agent's instructed bound + the parse
    |                       clamp) and the storyboard iteration — one value, so they cannot drift.
    |                       Default 8 (was 5): the shot count is now ADAPTIVE — a longer piece means more AND
    |                       longer beats — and a hard 5 silently capped every longer-form brief.
    |                       image_generate_max_calls_per_session AND image_edit_max_calls_per_session are kept
    |                       EQUAL to it so a full storyboard renders end-to-end WITH its authored filter (the
    |                       THREE-WAY COUPLING documented in the image-chain block above); raise ALL THREE (and
    |                       mind the timeout invariant) to allow more shots.
    |
    */

    'storyboard_max_shots' => (int) env('GENERATOR_STORYBOARD_MAX_SHOTS', 8),

    /*
    |--------------------------------------------------------------------------
    | Creative direction (the direction layer)
    |--------------------------------------------------------------------------
    |
    | A FULL run derives ONE creative direction up front — the shared frame (message, goal, audience, tone,
    | through-line, arc beats, subject, setting, visual style, target duration, continuity notes) that every
    | later generation in that run is made to, so a session produces ONE coherent piece instead of N mutually
    | blind AI calls. It is derived from the AUTHORED recipe via the NO-OP template preview (zero extra AI
    | spend to build the input), stored on the session, and REUSED by every per-part regenerate/refine.
    |
    | direction.enabled   The KILL SWITCH. False → nothing is derived, nothing is read from the column, and
    |                     nothing is injected: every composed prompt and agent instruction is byte-identical
    |                     to a direction-less run (pinned by a test). Default true.
    | direction.max_chars Length cap on the model's raw JSON reply (the per-field caps in the normalizer are
    |                     the real bound; this stops a runaway reply before it is even parsed).
    | direction.max_input_chars
    |                     Hard cap on the derivation INPUT (previewed recipe + scalar slot digest), so a
    |                     large recipe cannot inflate the one derivation call.
    |
    | COST: exactly ONE `ai_text` call per FULL run, metered + session-tagged + actor-attributed like every
    | other spend, and gated BEFORE spend by the workspace $ cap (an over-cap workspace derives nothing and
    | the run simply proceeds direction-less). It is deliberately NOT counted against
    | `ai_text_max_calls_per_session`: that budget bounds the AUTHOR-driven fan-out of `@[ai-text]` blocks,
    | and charging the direction to it would let one fixed call starve a real content part (a 4-block recipe
    | would only resolve 3). Zero derivation calls on regenerate/refine/per-shot ops.
    | TIMEOUT: bounded by `ai.direction_timeout` (30s), which is what keeps the run job's 300s SIGALRM
    | window intact (4 x 60 + 30 = 270s < 300s) without raising it.
    |
    */

    'direction' => [
        'enabled' => (bool) env('GENERATOR_DIRECTION_ENABLED', true),
        'max_chars' => (int) env('GENERATOR_DIRECTION_MAX_CHARS', 4000),
        'max_input_chars' => (int) env('GENERATOR_DIRECTION_MAX_INPUT_CHARS', 6000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Refine loop — per-part history / undo (R2 sub-stage 2d)
    |--------------------------------------------------------------------------
    |
    | A ready session's parts become iteratively refinable (regenerate one part / instructed refine) with
    | per-part history + undo. Each PRIOR result is pushed onto a bounded stack in the session's `history`
    | json column; produced images are stored VERSIONED so an image undo can restore an earlier version.
    |
    | history_max_versions  Max PRIOR versions kept per part (the undo depth). Once a part's stack exceeds
    |                       this, the OLDEST prior is dropped AND its produced-image blob is garbage-collected,
    |                       so history never grows without bound in the row or on disk.
    |
    | BUDGET NOTE: a regenerate/refine is its own claimed RUN, so it gets a FRESH per-run AI budget
    | (ai_text_max_calls_per_session / image_edit_max_calls_per_session). A single instructed refine is ONE
    | provider call (one text revision or one image edit), well inside those ceilings — the per-run budgets
    | already bound a runaway, so no separate refine budget is needed.
    |
    */

    'history_max_versions' => (int) env('GENERATOR_HISTORY_MAX_VERSIONS', 20),

    /*
    |--------------------------------------------------------------------------
    | Lifecycle reaper — stale recovery + retention (R2 sub-stage 2d)
    |--------------------------------------------------------------------------
    |
    | The scheduled `generator:reap-sessions` command sweeps the shared DB and every own-database
    | workspace (per-tenant isolated) and applies three windows, all measured in SECONDS. Floors of
    | 60s are enforced in the service so a misconfig can never reap/purge instantly.
    |
    | session_stale_after   A session stuck in `generating` past this window is marked `failed` — a
    |                       worker killed mid-run (SIGKILL/OOM) never fires the job's failed() hook and
    |                       nothing else would recover it (mirrors ai.disk_image_edit_timeout).
    |
    |                       STALE-TIMEOUT INVARIANT: this MUST EXCEED the run job's whole retry/lock
    |                       budget so a slow-but-alive run is NEVER reaped. RunGenerationSessionJob has
    |                       tries=1, timeout=300s (SIGALRM), and a WithoutOverlapping lock that
    |                       releaseAfter(30)+expireAfter(600) — so the widest window a live run can hold
    |                       `generating` (lock-held redeliveries until the lock self-expires) is ~600s.
    |                       Ordering: session_stale_after (1800s) > lock expireAfter (600s) >= job
    |                       timeout (300s) > worst-case run fan-out. The default 1800s (30 min) is ~3x
    |                       the lock expiry — generous headroom, like the Disk edit reaper's 900s. Raise
    |                       it (never lower it below the lock expiry) if the job timeout/lock ever grows.
    |
    | session_trash_after   A NON-archived session idle (updated_at) past this window is SOFT-DELETED
    |                       (trash). ~1 week. Any archived session (archived_at set) is EXEMPT. A refine
    |                       or edit bumps updated_at, so an actively-used session is never trashed.
    |
    | session_purge_after   A soft-deleted, NON-archived session whose trash (deleted_at) is past this
    |                       window is FORCE-DELETED and its produced-image blobs are garbage-collected
    |                       (GeneratedImageStore::clearSessionForWorkspace). ~1 month. An archived
    |                       (then manually trashed) session is EXEMPT — archive disables all cleanup.
    |
    */

    'session_stale_after' => (int) env('GENERATOR_SESSION_STALE_AFTER', 1800),

    'session_trash_after' => (int) env('GENERATOR_SESSION_TRASH_AFTER', 604800),

    'session_purge_after' => (int) env('GENERATOR_SESSION_PURGE_AFTER', 2592000),

];
