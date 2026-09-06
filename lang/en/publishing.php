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
    // CONNECTED ACCOUNTS (B2)
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    'connection_status' => [
        'active' => 'Connected',
        // NOT "expired" and not "error". What the reader has to understand is that only THEY can fix
        // it, and that it takes going back to the platform — not a retry button here.
        'needs_reauth' => 'Needs reconnecting',
        'revoked' => 'Disconnected',
    ],

    // Why a connection stopped working, as a code the client translates. Nothing branches on these
    // sentences, and none of them repeats what the platform said — that prose is composed on their
    // servers in whatever language they choose.
    //
    // THE KEYS ARE EXACTLY `PlatformConnectionManager::FAILURE_CODES`, in both languages, and
    // `PublishingConnectionVocabularyTest` refuses any drift in either direction. It has to: the two
    // sides drifted apart once already — the refresher wrote `token_refresh_failed` while this file
    // spelled it `refresh_failed` — and because the factory's default happened to match THIS file,
    // every test rendered a sentence and no test rendered the one a real failure would have produced.
    'connection_failures' => [
        'refresh_failed' => 'The platform would not renew this account\'s access. Connect it again to continue publishing.',
        'refresh_unsupported' => 'This connection has nothing left to renew with. Connect the account again.',
        // The APP_KEY case. Deliberately says what to DO rather than what happened, because what
        // happened is an administrator\'s problem and reconnecting is the user\'s remedy either way.
        'credentials_unreadable' => 'This account\'s stored access can no longer be read by the application. Connect it again.',
        // Written by `revoke()`. Until B2's review nothing wrote it, so a disconnected account kept
        // whatever code had last broken it and explained a renewal failure about an account somebody had
        // deliberately removed.
        'disconnected_by_user' => 'This account was disconnected.',
    ],

    // Why publications went on hold. Each names the CAUSE and the remedy, because the publication
    // itself is fine — what is broken is somewhere else entirely, and a message about the publication
    // would send somebody to fix the wrong thing.
    'holds' => [
        'connection_needs_reauth' => 'On hold: the account this goes out on needs reconnecting. Fix the connection and it will return to its scheduled time by itself.',
        'connection_disconnected' => 'On hold: the account this goes out on was disconnected. Connect it again, or choose another destination.',
    ],

    // What the callback puts in the redirect, as codes. The frontend owns the wording; these are here
    // so the server has one place naming what it can report.
    'oauth' => [
        'connected' => 'Account connected.',
        'failed' => 'The account could not be connected.',
        'oauth_state_expired' => 'That link expired. Start connecting the account again.',
        'oauth_state_already_used' => 'That link has already been used. Start connecting the account again.',
        // The handshake finished in a different browser from the one that started it. Deliberately does
        // NOT say "security" or name an attack: by far the commonest way to reach this is copying the
        // link into another window or having cookies switched off, and the remedy is the same either
        // way. Starting again in one browser is the whole instruction.
        'oauth_browser_mismatch' => 'This connection has to be finished in the same browser that started it. Start connecting the account again, and stay in this window.',
        'access_denied' => 'The permission request was declined, so nothing was connected.',
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

        // B2. The destination exists but has no account behind it — `dry_run` publishes nothing.
        'platform_not_connectable' => 'This destination has no account to connect. It is a rehearsal: nothing published to it leaves the application.',
        // The destination is real and this installation has no credentials for it yet. Says what is
        // missing rather than "something went wrong", because the remedy is an administrator's.
        'platform_not_configured' => 'This application is not yet registered with that platform, so an account cannot be connected. An administrator has to set that up first.',
        // The connection a publication names must exist, must serve the same destination, and must be
        // usable — three failures with one sentence, because a client that picked from the list this
        // server sent should never see any of them.
        'connection_unusable' => 'That account is not available for this destination.',
    ],

    // Codes an adapter reports, translated for the reader. The code is the contract; this is only its
    // wording — nothing branches on these sentences.
    'failures' => [
        'title_missing' => 'This publication has no title, so there is nothing to send.',
        'publish_outcome_unknown' => 'We lost contact with the platform and could not confirm what happened.',
        'reconciled_absent' => 'We checked the platform: nothing was published, so this can safely be tried again.',
    ],

];
