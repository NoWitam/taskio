<?php

namespace App\Modules\Workflows\Exceptions;

use RuntimeException;

/**
 * A step SUSPENDS its run: it has handed work to something outside this process (a queued
 * external job, a human decision, a third-party callback) and cannot publish its output yet. The
 * runner unwinds, parks the run in `waiting` with this exception's descriptors, and returns — later
 * steps do NOT run and NO step row is written. A FRESH job resumes the run later, rebuilding the
 * context from the database (never a serialized continuation).
 *
 * WHY A THROW AND NOT A SENTINEL RETURN VALUE. A step's return value is merged VERBATIM into
 * `context.steps.<key>`, so a magic marker key would (a) leak into the user-visible variable
 * catalog and (b) be able to collide with a real step output. The runner's step contract ALREADY
 * uses a throw for control flow — "a step that cannot proceed MUST throw" — so suspension is the
 * symmetric, NON-TERMINAL sibling of that failure signal. It also makes the sequential loop
 * trivially correct: unwind → persist → return, with no code path on which a suspended step's
 * (non-existent) output could be merged.
 *
 * ORDERING NOTE: the runner MUST catch this BEFORE `catch (Throwable)`. A later-ordered catch would
 * never fire, and the suspension would be recorded as a step failure.
 *
 *   $kind            which FLAVOUR of external work this is — the key the reaper's WaitResolver
 *                    registry is keyed by. Kind-agnostic to the engine: a string, not an enum, so
 *                    a feature module can introduce a wait kind without touching Workflows.
 *   $correlationKey  an opaque, globally unique handle for that work (`<kind>:<uuid>`), stored in
 *                    the indexed `waiting_key` column so a settle listener can find the run.
 *   $payload         anything the step needs back at resume time. NEVER put secrets or generated
 *                    content here — it is persisted VERBATIM in `waiting_on`, a column that must
 *                    never be serialized into an API Resource or logged. (No Resource exposes it
 *                    today — WorkflowRunResource emits no waiting field — and that is the invariant
 *                    this note protects, not a description of an existing reader.)
 */
class StepSuspended extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $correlationKey,
        public readonly array $payload = [],
    ) {
        parent::__construct('Step suspended awaiting external work.');
    }
}
