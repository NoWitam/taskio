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
        // The provider rendered the image and then refused to hand it over (its own moderation). Its own
        // message because it is the one image failure the USER can fix, and pointing them at "the base and
        // filters" would be actively wrong — the fix is the character's description or wardrobe.
        'image_safety' => 'The AI refused to deliver this image because of its content policy. Adjust the character description or wardrobe and try again.',
        // A storyboard frame whose render was interrupted (its worker died) and which the reaper gave up on.
        // Non-secret and actionable: the other frames are intact, so regenerating just this one is the fix.
        'frame_lost' => 'This frame could not be finished. Regenerate it to try again.',
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
