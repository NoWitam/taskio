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
    ],

];
