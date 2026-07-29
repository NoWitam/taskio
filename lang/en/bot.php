<?php

return [

    // Bot ↔ generation-session delegation (R2 sub-stage 3) — the only localized server messages: the
    // editable-state conflict responses. Everything else is structured (the FE renders the fill report).
    'delegation' => [
        'already_generating' => 'This session is generating. Wait for it to finish before delegating it to a bot.',
        'not_editable' => 'This session cannot be delegated in its current state.',
    ],

];
