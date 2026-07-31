<?php

namespace App\Modules\Generator\Exceptions;

/**
 * The image provider RENDERED the picture and then refused to hand it over, because its own safety system
 * rejected the content (`moderation_blocked`). The Generator-domain translation of Disk's
 * {@see \App\Modules\Disk\Exceptions\ImageSafetyRejectedException}, raised by the image chain so the session
 * executor keeps ONE catch for domain failures.
 *
 * It gets its own code because it is the ONE image failure the user can actually fix, and the generic
 * "check its base and filters" message actively points them the wrong way: the fix is the CHARACTER's
 * description or wardrobe, not the plan. The identity spike hit exactly this wall — the same character comes
 * back refused as `[sexual]` in a swimsuit and fine in a dress — and a run that shows a long wait ending in
 * a generic failure teaches nobody that.
 *
 * Deterministic: a retry buys the same refusal at the same price, so it is never retried, only failed soft
 * (the part/frame is `failed`, every other part still runs). The message carried here is APP-AUTHORED and
 * content-free — never the prompt, never the identity, never the provider body — because a fail-soft part
 * failure IS logged with its exception message.
 */
class ImageSafetyRejected extends ImageChainException
{
    public function __construct()
    {
        parent::__construct('The image provider refused the rendered content (moderation).');
    }

    public function messageKey(): string
    {
        return 'generator.sessions.image_safety';
    }

    /**
     * The one image failure worth a machine-readable code, for the same reason it is worth its own message:
     * the fix is specific (the character's description or wardrobe) and the UI can offer it instead of
     * parsing prose. Named for the DOMAIN concept rather than the provider's `moderation_blocked`, so a
     * different provider's refusal reaches the client as the same thing.
     */
    public function errorCode(): ?string
    {
        return 'image_safety';
    }
}
