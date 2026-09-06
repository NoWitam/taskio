<?php

return [

    // Every user-facing string this module produces. The app is PL+EN switchable and server prose
    // follows the READER's language, never APP_LOCALE — so nothing here may be hardcoded at a call
    // site, including the calendar badge and the source's own name, both of which travel as prose
    // precisely so no client has to own a vocabulary per platform.

    'status' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'publishing' => 'Publishing',
        'published' => 'Published',
        'failed' => 'Failed',
        // NOT "failed" and not "retrying". The whole point of this status is that we do not know
        // whether a post exists, and the wording has to make somebody look rather than click retry.
        'needs_reconcile' => 'Needs checking',
        'blocked' => 'On hold',
    ],

    'platforms' => [
        'youtube' => 'YouTube',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        // Named so nobody can select it believing something will be posted.
        'dry_run' => 'Test run (nothing is published)',
    ],

    // The source's name on the calendar's filter chip. Translated here, by the module that owns the
    // subject, which is what keeps the Calendar from needing an entry for a source it has never heard
    // of.
    'calendar' => [
        'source' => 'Publications',
    ],

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // REFUSED STATE MOVES
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // Each one has to say what to do next, not merely that something was refused: a person reading
    // these has a post that may or may not exist and a button that did nothing.
    'transitions' => [
        // THE most important sentence in this file.
        'reconcile_before_retry' => 'This publication may already be live — we lost contact before we could confirm it. Check the platform first; publishing again could post it twice, and a published post cannot be withdrawn from here.',
        'blocked_holds' => 'This publication is on hold because its connection is not working. Reconnect the account, then schedule it again — publishing now would fail for every item waiting on that connection.',
        'terminal' => 'This has already been published. It cannot be changed from here.',
        'not_allowed' => 'A publication cannot go from “:from” to “:to”.',
    ],

    'validation' => [
        // The machine's own columns, refused rather than dropped: a client sending one believes it is
        // setting something, and being quietly ignored leaves it correct-looking and wrong.
        'status_not_accepted' => 'A publication\'s status is set by scheduling and publishing it, not sent with the request.',
        'remote_not_accepted' => 'What the platform sent back is recorded when it happens; it is not part of a request.',
        'platform_unknown' => 'That is not a destination this application can publish to.',
        'scheduled_at_unreadable' => 'That does not look like a date and time.',
        'scheduled_in_the_past' => 'That moment has already passed. Pick a time in the future, or publish now.',
    ],

    // Codes an adapter reports, translated for the reader. The code is the contract; this is only its
    // wording — nothing branches on these sentences.
    'failures' => [
        'title_missing' => 'This publication has no title, so there is nothing to send.',
        'publish_outcome_unknown' => 'We lost contact with the platform and could not confirm what happened.',
        'reconciled_absent' => 'We checked the platform: nothing was published, so this can safely be tried again.',
    ],

];
