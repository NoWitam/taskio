<?php

namespace App\Modules\Generator\Exceptions;

/**
 * The image plan's BASE could not be resolved to readable image bytes: a `disk_file` whose id is missing,
 * foreign (WorkspaceScope hid it), non-image or blob-less; a `from_slot` whose file-typed slot was never
 * filled; or an absent/unknown base kind. Fail-soft — the part is marked `failed`, every other part runs.
 */
class ImageBaseUnavailable extends ImageChainException
{
    public function messageKey(): string
    {
        return 'generator.sessions.image_base_unavailable';
    }
}
