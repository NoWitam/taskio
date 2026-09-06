<?php

namespace App\Modules\Publishing\Exceptions;

use App\Modules\Publishing\Enums\PublishingPlatform;
use RuntimeException;

/**
 * No adapter is registered for a destination — a CONFIGURATION ERROR, and loud on purpose.
 *
 * The Calendar's source registry fails soft: a broken source is logged, skipped, and the rest of the
 * grid renders, because a calendar aggregates independent modules and one module's bad migration must
 * not blank a screen that is mostly other modules' data.
 *
 * NONE OF THAT REASONING TRANSFERS HERE, and the difference is worth stating because "be consistent
 * with the registry we copied" is the obvious mistake. There is nothing to degrade to: a publication
 * whose destination this installation does not implement cannot be half-published, and skipping it
 * would leave a row silently sitting in `scheduled` forever with nothing anywhere saying why. The row
 * was armed by somebody who expects a post to appear.
 *
 * So it throws, and the publisher's own doubt-handling does the rest: an unrecognised throwable during
 * a publish parks the publication in `needs_reconcile` rather than failing it — which is right even
 * here, because a build that lost its adapter may be a build that lost it BETWEEN two phases.
 */
class UnknownPlatformAdapter extends RuntimeException
{
    public function __construct(public readonly PublishingPlatform $platform)
    {
        parent::__construct(
            'No publishing adapter is registered for [' . $platform->value . ']. '
            . 'Register one from the owning module\'s boot() via PlatformAdapterRegistry::registerLazy().'
        );
    }
}
