<?php

namespace App\Modules\Generator\Enums;

/**
 * The MODE of one generation-run job (R2 sub-stage 2d). The async claim/job/tenancy machinery is shared by
 * a WHOLE-session generate and the per-part refine loop — the mode tells the run manager which unit of work
 * a claimed run performs, so no forked engine is needed:
 *
 *   - full        the whole-session generate (2b/2c): render EVERY part fresh, clearing prior results +
 *                 produced-image blobs at claim. Every part lands at version 1.
 *   - regenerate  re-run ONE part from the SAME snapshot recipe + slot values (a fresh variation): the prior
 *                 result is pushed to that part's history and a new version is written; other parts untouched.
 *   - refine      a REVISION of ONE part's CURRENT output guided by a free-text instruction (text → an AI
 *                 text revision; image → an AI edit of the current image): prior → history, new version.
 *
 * A string-backed enum so the value rides the queued job's scalar payload (like the session id / workspace id)
 * and maps back with a fail-safe {@see tryFrom} default of `full` on the worker.
 */
enum GenerationRunMode: string
{
    case Full = 'full';
    case Regenerate = 'regenerate';
    case Refine = 'refine';
}
