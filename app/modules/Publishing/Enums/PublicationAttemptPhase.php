<?php

namespace App\Modules\Publishing\Enums;

/**
 * WHICH HALF of a publish an attempt row is about.
 *
 * The two-phase shape is not this module's invention — it is what both target families actually do:
 *
 *   INSTAGRAM   POST /media (a "container") … then POST /media_publish on that container.
 *   YOUTUBE     a resumable upload session URI … then the bytes and the final commit.
 *
 * The gap between the two phases is where a crash costs a public artifact, so it is modelled rather
 * than hidden behind one call: {@see DRAFT} is what produced `publications.remote_draft_id`, and
 * {@see PUBLISH} is what consumed it. An attempt log split this way answers the only question that
 * matters after a crash — how far did we get — from the rows themselves.
 *
 * {@see RECONCILE} is the third thing that talks to a platform and it is not a phase of publishing at
 * all: it ASKS whether an artifact exists. It is logged here because the answer is evidence, and
 * evidence about an irreversible act belongs in the same trail as the act.
 */
enum PublicationAttemptPhase: string
{
    /** Phase 1 — create the intermediate artifact and hand back the handle to persist. */
    case DRAFT = 'draft';

    /** Phase 2 — turn an already-created intermediate artifact into a public one. */
    case PUBLISH = 'publish';

    /** Not a phase: an enquiry. "Does the artifact this row would have created already exist?" */
    case RECONCILE = 'reconcile';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
