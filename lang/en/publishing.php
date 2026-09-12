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

    // The TWO OUTCOMES the callback puts in the redirect. The reason codes are below, in their own map,
    // for the same reason `connection_failures` is its own map: one of these lists is pinned to a
    // constant and the other is not.
    'oauth' => [
        'connected' => 'Account connected.',
        'failed' => 'The account could not be connected.',
    ],

    // WHY connecting an account failed, as codes the client translates.
    //
    // THE KEYS ARE EXACTLY `OAuthCallbackReason::ALL`, in both languages, and
    // `PublishingConnectionVocabularyTest` refuses any drift in either direction. Until B3 only four of
    // these existed here while the callback could report fourteen — so ten of them would have rendered
    // as their own raw key on the screen a person lands on at the end of a failed consent flow, which
    // is the worst possible moment to be shown a machine identifier.
    //
    // Each sentence says WHAT TO DO. Somebody reading one has just been bounced back from Google or Meta
    // with nothing connected, and "an error occurred" tells them nothing they had not already worked
    // out. None of them repeats a platform's own prose — that is composed on their servers in whatever
    // language they choose — and none names an attack, because the commonest way to reach even the
    // security refusals is a copied link or a browser with cookies switched off.
    'oauth_failures' => [
        'unknown_platform' => 'That is not a service this application can connect an account to.',
        'missing_code' => 'The platform sent you back without granting access. Start connecting the account again.',
        // Three refusals share this sentence on purpose — see the controller. Which of them happened is
        // deliberately not disclosed to whoever is holding the link.
        'workspace_unavailable' => 'This account cannot be connected to that workspace any more. Check that you still have access to it, then try again.',
        'connection_failed' => 'Something went wrong while connecting the account and nothing was saved. Try again; if it keeps happening, an administrator will need to look at the logs.',
        'access_denied' => 'The permission request was declined, so nothing was connected.',

        'oauth_state_malformed' => 'That link is not one we recognise. Start connecting the account again from this application.',
        // The one that means somebody tried. Says nothing about that: a user who reached it by clicking
        // a stale link deserves the same instruction, and naming an attack would alarm the wrong person.
        'oauth_state_bad_signature' => 'That link could not be verified. Start connecting the account again from this application.',
        'oauth_state_expired' => 'That link expired. Start connecting the account again.',
        'oauth_state_already_used' => 'That link has already been used. Start connecting the account again.',
        'oauth_state_platform_mismatch' => 'That link was for a different service. Start again from the account you meant to connect.',
        // The handshake finished in a different browser from the one that started it. Deliberately does
        // NOT say "security" or name an attack: by far the commonest way to reach this is copying the
        // link into another window or having cookies switched off, and the remedy is the same either
        // way. Starting again in one browser is the whole instruction.
        'oauth_browser_mismatch' => 'This connection has to be finished in the same browser that started it. Start connecting the account again, and stay in this window.',

        // The token endpoint refused. The remedy is a person's in the first two cases and an
        // administrator's in the third, and each says which rather than offering a generic retry.
        'token_exchange_failed' => 'The platform would not grant access to this account. Try again, and make sure you are signed in to the right account there.',
        'token_response_unusable' => 'The platform granted access in a form this application cannot store. Start connecting the account again and accept every permission it asks for.',
        'account_lookup_failed' => 'Access was granted, but the platform would not say which account it was for — so there was nothing to save. Check that the account has a channel or page this application can post to, then try again.',
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
        // Nothing is wrong with the move; the screen is simply out of date. Says where the publication
        // actually is, because that is the only thing the reader needs in order to decide again.
        'lost_race' => 'Something else has already dealt with this publication — it is now “:from”. Nothing was changed. Refresh to see where it stands.',
    ],

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // THE REVIEW CARD (B6)
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // What an approver reads about a publication before deciding, plus the one refusal a live review
    // produces. Every label here names something whose value would change what the world sees — there is
    // nothing decorative in this list.
    //
    // `under_review` is DELIBERATELY NOT in `transitions` above. That catalog is pinned key-for-key to
    // `PublicationTransitionRefused`'s own vocabulary (see PublishingConnectionVocabularyTest), and a
    // review hold is not a transition the machine refused — it is a row somebody is deciding about.
    'approval' => [
        // NOT "you may not" — everybody gets this answer, including whoever created it, and none of them
        // has a permission problem. Says who has it and what ends the wait.
        'under_review' => 'This publication is with an approver, so it cannot be changed or scheduled right now. Once the review is finished it goes back to being editable — and if it was set up to publish automatically, approving it is what schedules it.',
        'fields' => [
            'platform' => 'Destination',
            'account' => 'Account',
            'planned_for' => 'Planned for',
            'media' => 'Attached media',
        ],
        // A rehearsal destination has no account, and so does a publication whose connection was deleted
        // outright. Both read as a stated absence rather than as a blank an approver has to interpret.
        'no_account' => 'No account (nothing is published)',
        // Approving a post for Friday and approving one for "as soon as you say yes" are different acts,
        // so the second one is said out loud rather than left as an empty cell.
        'no_moment' => 'As soon as it is approved',
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

        // ── B3, the queue's own vocabulary ────────────────────────────────────────────────────────
        // The queue could not be written to, so nothing was ever sent. That is a `failed`, not a
        // `needs_reconcile`, and the sentence says so plainly: there is nothing to check.
        'dispatch_failed' => 'This could not be handed to a worker, so nothing was sent anywhere. Schedule it again.',
        // The worker died holding the claim. Says what to DO, and does not offer a retry — from here
        // the only honest next step is to look.
        'publish_worker_failed' => 'The worker handling this publication stopped before it could tell us what happened. Check the platform before scheduling it again.',
        // Nobody ever came back for it. The same instruction, with the reason a person can act on.
        'reaper_stale' => 'This was claimed for publishing and nothing came back. Check the platform before scheduling it again — it may already be live.',

        // ── THE FLOOR (D4) ────────────────────────────────────────────────────────────────────────
        // A FAITHFUL COPY of the sentence the client has carried since B3 (`failures.unknown` in en.ts),
        // not a new sentence about failure: the server only needed one now, because the failed-publication
        // mail is the first server-side prose that renders a failure code. B4 will add adapter codes this
        // build has never heard of, and an inbox is the worst possible place to show a raw key. The code
        // is NAMED so a support conversation has something to start from.
        'unknown' => 'The publication stopped for a reason this version of the application does not describe yet (:code).',
    ],

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // THE FAILED-PUBLICATION MAIL (D4)
    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // The third operational mail of this product, after the invitation and the password reset. It lives
    // here, beside the `failures` catalog, because the letter is ASSEMBLED from those sentences — it
    // describes no failure of its own.
    //
    // "Nothing was sent" may only be said because this letter goes out for `failed` alone, whose contract
    // is "the platform was asked and PROVED nothing was created". For `needs_reconcile` the sentence
    // would be false — which is exactly why that status is not a conclusion and sends no letter.
    'mail' => [
        'failed' => [
            'subject' => 'A publication did not go out: :title',
            'greeting' => 'Hi :name,',
            'intro' => 'The publication “:title” was meant to go out on :platform and did not. Nothing was sent.',
            'planned' => 'Planned for :moment (:timezone).',
            'action' => 'Open the publication',
            'fallback' => 'Or paste this link into your browser:',
            'footer' => 'Taskio sends this letter whenever a publication fails.',
            // The subject/body stand-in for a BLANK title (the workflow path lets one through to
            // `title_missing` on purpose) — without it the subject ends in ": " and the intro quotes "".
            'untitled' => 'Untitled publication',
        ],
    ],

];
