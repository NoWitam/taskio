<?php

return [

    // Bot ↔ generation-session delegation (R2 sub-stage 3) — the only localized server messages: the
    // editable-state conflict responses. Everything else is structured (the FE renders the fill report).
    'delegation' => [
        'already_generating' => 'This session is generating. Wait for it to finish before delegating it to a bot.',
        'not_editable' => 'This session cannot be delegated in its current state.',
    ],

    // The VISUAL module: creating and curating the bot's likeness.
    'visual' => [
        // Ownership refusal for any file id in the module (candidates/canonical must belong to
        // this bot; the reference may also be a file from the Disk).
        'invalid_file' => 'This file does not belong to this bot.',
        'reference_required' => 'Choose a source image: upload one or pick one from your Disk.',
        'reference_unreadable' => 'The source image could not be read. Pick another one.',
        // Nothing to draw from: the module has no description and the request added no instruction.
        'nothing_to_generate' => 'Describe how the bot should look before generating an image.',
        'not_a_candidate' => 'This image is not one of this bot\'s generated candidates.',
        'canonical_locked' => 'This is the approved image. Approve a different one (or clear the approval) before deleting it.',
    ],

];
