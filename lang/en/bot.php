<?php

return [

    // Bot ↔ generation-session delegation (R2 sub-stage 3) — the only localized server messages: the
    // editable-state conflict responses. Everything else is structured (the FE renders the fill report).
    'delegation' => [
        'already_generating' => 'This session is generating. Wait for it to finish before delegating it to a bot.',
        'not_editable' => 'This session cannot be delegated in its current state.',
    ],

    // The workspace AI budget refused a bot run before it started. Recorded on the task's timeline as
    // the run's failure reason, so a bot that has stopped working says WHY instead of looking broken.
    'budget' => [
        'run_refused' => 'The workspace has reached its monthly AI budget, so this run was not started. '
            . 'Raise the limit or wait for the next billing month.',
    ],

    // The KNOWLEDGE module (B6): wiring a bot to a real knowledge base, and lifting its legacy
    // built-in entries into one.
    'knowledge' => [
        'base_name' => 'Knowledge: :bot',
        'base_description' => 'Migrated from the built-in knowledge module of the bot ":bot".',
        'base_charter' => 'What the bot ":bot" must know to work on its tasks. It was migrated from the '
            . 'bot\'s built-in knowledge module; edit it here from now on.',
        'migration_note' => 'Migrated from the built-in knowledge module of the bot ":bot"',
        'nothing_to_migrate' => 'This bot has no built-in knowledge entries to migrate.',
        // Fail-closed: the knowledge store refuses template syntax, and the bot's own column never did.
        'migration_blocked' => 'These entries contain template syntax (@[...], {{ ... }}, conditional '
            . 'blocks) and cannot be migrated: :titles. Remove it in the bot editor, then migrate again.',
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
