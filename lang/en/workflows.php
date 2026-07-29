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

    // Workflow STEPS — text a step WRITES into the domain (not failure prose).
    'steps' => [
        'generate_content' => [
            // The Disk name a generated image is exported under; the part key is appended, so a
            // multi-image recipe does not produce a folder of identically-named files.
            'image_name' => 'Generated image',
        ],
    ],

];
