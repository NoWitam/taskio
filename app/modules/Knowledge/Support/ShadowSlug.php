<?php

namespace App\Modules\Knowledge\Support;

use Illuminate\Support\Str;

/**
 * The RESERVED address a shadow draft occupies.
 *
 * A shadow draft proposes a change to an entry that already exists. It is still a row in
 * `knowledge_entries`, so it still needs a slug — and that slug must never be mistaken for a real one.
 * Two things would break if it were:
 *
 *   A `[[link]]` in a sibling draft written as `[[cennik]]` must resolve to the LIVE `cennik`, not to
 *   the shadow proposing to amend it. The shadow is a proposal about that entry, not a second entry
 *   with the same subject.
 *
 *   Slug de-collision must not treat a shadow as occupying the target's address, or the next real
 *   entry called `cennik` would be pushed to `cennik-2` by a row nobody can see.
 *
 * So the address is synthetic and unmistakable: `__shadow-<ulid>`. The leading double underscore
 * cannot be produced by `Str::slug` (it strips leading punctuation and collapses separators), so no
 * human title and no model-proposed slug can ever collide with the reserved space. It is never shown:
 * the UI renders the TARGET's title, because that is what the proposal is about.
 */
class ShadowSlug
{
    public const PREFIX = '__shadow-';

    /** A fresh, collision-free address for one shadow draft. */
    public static function mint(): string
    {
        return self::PREFIX . strtolower((string) Str::ulid());
    }

    /** Whether a slug names the reserved shadow space. */
    public static function is(?string $slug): bool
    {
        return is_string($slug) && str_starts_with($slug, self::PREFIX);
    }
}
