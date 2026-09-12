<?php

return [

    // Workflow RUNS — the engine's user-visible failure prose. These land in `workflow_runs.error`,
    // which the run detail shows verbatim, so they must read as an explanation, never as a stack
    // trace. NON-SECRET by construction: no step content, no correlation key, no payload.
    'runs' => [
        // Suspend/resume engine. A run parked in `waiting` can end badly in three ways, and the three
        // messages stay distinct because they call for three different user reactions.
        'definition_changed' => 'This workflow was changed while the run was waiting, so the run could not continue where it left off. Start it again.',
        'wait_gone' => 'The work this run was waiting for no longer exists, so the run could not continue. Start it again.',
        'wait_timed_out' => 'This run waited too long for its work to finish and was stopped. Start it again.',
        'resume_without_wait' => 'This run could not be continued because there is no record of what it was waiting for.',
    ],

    // Workflow STEPS — text a step WRITES into the domain, plus a step's OWN refusal prose (the engine's
    // lives under `runs` above). NON-SECRET by construction: no ids, no step content.
    'steps' => [
        'generate_content' => [
            // The Disk name a generated image is exported under; the part key is appended, so a
            // multi-image recipe does not produce a folder of identically-named files.
            'image_name' => 'Generated image',

            // RUN TIME: the step names an author (`bot_id`) that no longer resolves in this workspace, so
            // the generation was refused. It is a refusal, not a degradation — publishing the piece in
            // nobody's voice and without the intended likeness is not a lesser version of what was asked
            // for. Lands in `workflow_runs.error`.
            'bot_unavailable' => 'The bot this step generates content as is no longer available in this workspace, so nothing was generated. Pick another bot for the step, or remove it.',

            // AUTHOR TIME: the same check, run when the workflow is saved, so the definition cannot be
            // stored in a state that would fail every single run.
            'bot_invalid' => 'The selected bot is not available in this workspace.',
        ],

        // R4 B6. Everything a `publish` step can refuse with. All of these land verbatim in
        // `workflow_runs.error`, which a person reads — so each says what happened and, where there is
        // one, what to do. None of them repeats a platform's own prose or names a credential.
        'publish' => [
            // AUTHOR TIME: asked when the workflow is saved, through the same authority the run uses, so
            // a definition cannot be stored in a state that would fail every single run.
            'connection_invalid' => 'That account is not available for this destination. Pick a connected account that publishes there — only a test destination can go without one.',

            // RUN TIME: the same question, asked again, because a token can be revoked or an account
            // disconnected while a workflow sleeps. Refused BEFORE anything is created, so no draft is
            // left behind.
            'connection_unavailable' => 'The account this step publishes as is no longer usable, so nothing was created. Reconnect it, or point the step at another account.',

            // The publication reached a real outcome and it was not publication. The code is the module's
            // stable failure code, which is also what the publishing screen translates.
            'failed' => 'The publication this step was waiting for ended as “:status” (:code), so nothing was published.',

            // A reviewer said no. The publication stays a draft and can be fixed and sent again — but
            // this run stops, because every step after it assumed something had gone out.
            'rejected' => 'The publication this step was waiting for was not approved, so nothing was published.',

            // Deleted or purged while the run waited. It can never reach an outcome, so waiting on would
            // only turn the real cause into a misleading timeout.
            'gone' => 'The publication this step was waiting for no longer exists, so its outcome could not be collected.',
        ],
    ],

    // R3 Calendar. Source names and occurrence badges are translated SERVER-side and carried in the
    // calendar response: the calendar screen must be able to render a source it has never heard of, or
    // "adding a source needs no frontend change" stops being true on the very first one.
    'calendar' => [
        'schedule_source' => 'Scheduled automations',
        'run_source' => 'Automation runs',
        'scheduled_badge' => 'Planned',
        // A run whose workflow has since been deleted still happened, and its square still has to say
        // something.
        'run_untitled' => 'Deleted automation',

        // How often a scheduled automation repeats — the prose that makes the calendar's density
        // marker informative ("Series — showing 64" says nothing; "Every 5 min" says why). Only the
        // INTERVAL time modes have one; see ScheduleCadenceLabel for why fixed-time schedules do not.
        'cadence' => [
            'every_minutes' => 'Every :count min',
            'every_hours' => 'Every :count h',
            // The interval plus its optional active window.
            'within' => ':cadence, :window',
        ],
    ],

    // STEP TYPES as prose. Used by the variable catalog, which ships "<step type> · <output>" as the
    // display name of every step-output variable. The workflow EDITOR words step types from its own
    // catalogue, so nothing matches on these strings.
    'step_types' => [
        'create_task' => 'Create task',
        'create_form_report' => 'Create form report',
        'generate_content' => 'Generate content',
        'create_event' => 'Create calendar event',
        // R4 B6. Named for what it queues, not for what it calls: the step arms a publication and the
        // publishing sweep is what actually posts it.
        'publish' => 'Publish',
    ],

    // Run states as PROSE, for the places the server has to word them itself (the calendar badge, and
    // `state_label` on the runs API). The enum's label() reads these now: it used to hardcode Polish,
    // which was the last remnant of the app-wide defect where server prose followed APP_LOCALE instead
    // of the reader's language. Nothing matches on the prose — the runs UI keys off `run.state` and
    // treats `state_label` only as a fallback — so this is the fix landing, not a contract change.
    'run_states' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'waiting' => 'Waiting',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

];
