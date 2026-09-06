<?php

namespace App\Modules\Publishing\Services;

use App\Modules\Publishing\Contracts\PlatformAdapter;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\UnknownPlatformAdapter;
use Closure;
use RuntimeException;

/**
 * Which destinations this installation can actually publish to.
 *
 * Modelled on `CalendarSourceRegistry`, the established registry shape in this codebase, down to the
 * in-place memoization and the register()/boot() split — with ONE deliberate divergence, stated below
 * because copying it would be the natural thing to do.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * PROVIDER ORDER IS NOT A DEPENDENCY, AND THAT IS BY CONSTRUCTION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The registry is BOUND as a singleton in `PublishingModuleServiceProvider::register()`, and adapters
 * register themselves in a boot(). Laravel runs every register() before any boot(), so an adapter can
 * never write into a registry that does not exist yet — the failure mode being designed out is an
 * entry that vanishes without a word.
 *
 * Registration is LAZY. Real adapters will hold HTTP clients and credential resolvers; constructing
 * every one of them on every boot, to serve requests that never publish anything, is a cost nobody
 * asked for.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * NO FAIL-SOFT — THE ONE PLACE THIS PARTS COMPANY WITH THE CALENDAR
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The Calendar logs and skips a source that throws, because a grid is an aggregate and one broken
 * module must not blank the others' data. There is no aggregate here and nothing to degrade to: a
 * publication whose destination has no adapter cannot be partly published. Skipping it would leave a
 * row armed for a moment that passes, forever, with nothing anywhere explaining the silence — and
 * somebody is waiting for that post.
 *
 * So every failure to produce an adapter throws {@see UnknownPlatformAdapter}, and the publisher's own
 * doubt-handling decides what that means for the row's state.
 */
class PlatformAdapterRegistry
{
    /** @var array<string, PlatformAdapter|Closure(): PlatformAdapter> */
    private array $adapters = [];

    /**
     * Register an adapter WITHOUT constructing it.
     *
     * @param  Closure(): PlatformAdapter  $factory
     */
    public function registerLazy(PublishingPlatform $platform, Closure $factory): void
    {
        $this->guardOverwrite($platform);

        $this->adapters[$platform->value] = $factory;
    }

    /** Register an already-built adapter. Rare — real adapters are lazy. */
    public function register(PlatformAdapter $adapter): void
    {
        $this->guardOverwrite($adapter->platform());

        $this->adapters[$adapter->platform()->value] = $adapter;
    }

    public function has(PublishingPlatform $platform): bool
    {
        return array_key_exists($platform->value, $this->adapters);
    }

    /**
     * Every destination this installation can publish to, in registration order.
     *
     * @return array<int, PublishingPlatform>
     */
    public function platforms(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?PublishingPlatform => PublishingPlatform::tryFrom($value),
            array_keys($this->adapters),
        )));
    }

    /**
     * The adapter for a destination, constructing and memoizing it on first use.
     *
     * The claim check mirrors the Calendar's and closes the same hole: the registration KEY is what
     * routes a publication, while the adapter's own `platform()` is what it believes it serves. Let the
     * two disagree and a publication is handed to an adapter for somewhere else — which, unlike a
     * calendar square nobody can filter, would post the wrong content to the wrong account. It is
     * refused rather than logged.
     *
     * @throws UnknownPlatformAdapter when nothing is registered, or the factory produced nothing usable
     */
    public function resolve(PublishingPlatform $platform): PlatformAdapter
    {
        $adapter = $this->adapters[$platform->value] ?? null;

        if ($adapter === null) {
            throw new UnknownPlatformAdapter($platform);
        }

        if ($adapter instanceof Closure) {
            $built = $adapter();

            if ($built->platform() !== $platform) {
                throw new RuntimeException(
                    'Publishing adapter registered under [' . $platform->value . '] claims to serve ['
                    . $built->platform()->value . ']. A publication routed by the key would be handed to '
                    . 'an adapter for somewhere else.'
                );
            }

            // Assigned only on success, so a transient construction failure does not poison the slot
            // for the rest of the process.
            $adapter = $this->adapters[$platform->value] = $built;
        }

        return $adapter;
    }

    /**
     * Two modules claiming one destination is a configuration error that would otherwise resolve itself
     * silently in favour of whichever provider booted last — and "publishes to the wrong account" is
     * not a class of bug worth discovering in production.
     */
    private function guardOverwrite(PublishingPlatform $platform): void
    {
        if (array_key_exists($platform->value, $this->adapters)) {
            throw new RuntimeException(
                'A publishing adapter is already registered for [' . $platform->value . ']. '
                . 'Two adapters for one destination is a configuration error, not a fallback.'
            );
        }
    }
}
