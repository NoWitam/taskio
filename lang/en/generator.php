<?php

return [

    // Generation SESSIONS (R2 sub-stage 2b) — the only localized server messages: state-conflict
    // responses + a per-part failure fallback. Response prose is otherwise structured (the FE renders).
    'sessions' => [
        'already_generating' => 'This session is already generating. Wait for it to finish.',
        'not_editable' => 'This session cannot be edited while it is generating.',
        'template_not_found' => 'The selected template could not be found.',
        'template_forbidden' => 'You do not have access to the selected template.',
        'part_failed' => 'This part could not be generated. Check its references and try again.',
        // Image chain (R2 sub-stage 2c) — localized, NON-SECRET per-part failures (never the prompt/base).
        'image_failed' => 'This image could not be generated. Check its base and filters and try again.',
        'image_base_unavailable' => 'The image source could not be found. Check the base file or slot and try again.',
        'image_budget' => 'This image has too many AI edits to generate in one run. Reduce the number of AI edits and try again.',
        'image_generate_budget' => 'This recipe generates too many AI images in one run. Reduce the number of AI-generated images and try again.',
        'ai_generate_unsupported' => 'Generating an image from a text prompt is not available yet.',
        'saved_image_name' => 'Generated image',
        'deleted' => 'Session deleted successfully.',
        // Refine loop (R2 sub-stage 2d) — localized, NON-SECRET conflict / capability messages.
        'nothing_to_undo' => 'There is nothing to undo for this part.',
        'refine_not_supported' => 'This part cannot be refined with an instruction.',
        'refine_no_image' => 'There is no generated image to refine yet. Generate it first.',
        // Pre-run AI-budget gate (R2 sub-stage 4) — the up-front over-cap refusal (HTTP 429). Non-secret.
        'ai_budget_exceeded' => 'This workspace has reached its monthly AI budget. Raise the cap or wait for the next month to generate again.',
    ],

];
