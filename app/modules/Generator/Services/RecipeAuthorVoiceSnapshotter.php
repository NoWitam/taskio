<?php

namespace App\Modules\Generator\Services;

use App\Modules\Variables\Contracts\AuthorVoiceResolver;
use App\Modules\Variables\Services\VariableResolver;
use Throwable;

/**
 * FREEZES the per-block `@[ai-text]` AUTHOR voices of a recipe at session-creation time — the author-side
 * twin of the delegation overlay's snapshotted `bot_delegation.voice`, and the same rule the whole
 * `recipe_snapshot` follows (ADR-0034 D1): what a session produces is decided by what the recipe looked
 * like WHEN IT WAS CREATED. Editing a bot's persona, or deleting it outright, must never change the output
 * of a session that already exists — including one that has not been run yet, or that is re-run/refined
 * weeks later.
 *
 * CONTENT-SHAPE AGNOSTIC BY CONSTRUCTION: it walks EVERY string leaf of the recipe's `content` (whatever
 * the shape — `body`, `image_plan`, `shot_list.brief`, `storyboard.style`, and every kind still to come)
 * and scans each through the SHARED {@see VariableResolver::collectAiTextAuthorIds}. It deliberately knows
 * NOTHING about part kinds: a new content type must not require a change here to keep its authors. The
 * walk mirrors {@see GenerationSessionRefiner}'s reference scan for exactly the same reason — one scanner,
 * so a collected author can never drift from a block the resolver would actually execute.
 *
 * ONE BATCH LOOKUP: every id the whole recipe mentions is gathered first and resolved in a SINGLE
 * {@see AuthorVoiceResolver::voicesFor} call, so a recipe with many authored blocks costs one query, not one
 * per block.
 *
 * FAIL-SAFE: the resolver never throws and simply OMITS an id it cannot resolve (unknown / foreign /
 * malformed), so a snapshot may legitimately be missing an author that was named — the executor then falls
 * back to the session voice and finally to the block's persona tone. The frozen map holds OPAQUE authored
 * directives and is NEVER logged. That promise is ALSO upheld here rather than merely trusted (see
 * {@see snapshot}): this runs on the INTERACTIVE create path, so a violating implementation must cost the
 * authored tone, never the session.
 *
 * BOUNDARY: it names only the Variables CONTRACT, never the module that owns authors (Bot) — Generator
 * stays Bot-free (pinned by GeneratorModuleBoundaryTest).
 */
class RecipeAuthorVoiceSnapshotter
{
    public function __construct(
        private VariableResolver $resolver,
        private AuthorVoiceResolver $authors,
    ) {}

    /**
     * The `{authorId: opaque voice}` map to freeze into a recipe snapshot, for the recipe's `content`.
     *
     * $workspaceId is the EXPLICIT tenant boundary the lookup must run under (null ONLY in own-database
     * mode, where the dedicated connection is the boundary) — never left to the ambient scope, because
     * this is reachable from a QUEUED automated create where that scope is a documented no-op.
     *
     * @param  array<string, mixed>  $content  the recipe's per-part authored content map
     * @return array<string, string> empty when the recipe names no author (the overwhelmingly common case)
     */
    public function snapshot(array $content, ?string $workspaceId): array
    {
        $ids = $this->authorIdsIn($content);

        if ($ids === []) {
            return [];
        }

        try {
            return $this->authors->voicesFor($ids, $workspaceId);
        } catch (Throwable) {
            // The contract PROMISES never-throws; a violating implementation must cost the authored TONE,
            // never the session. Without this, a provider/DB fault here would 500 the interactive create
            // (and fail the QUEUED automated one) for a recipe that merely NAMES an author — turning a
            // fail-safe degradation into a hard outage. Mirrors the same consumer-upheld guard in
            // WorkflowStepRunner. NOT reported: a QueryException message carries the statement AND its
            // bindings, i.e. the author ids, which must never reach a log.
            return [];
        }
    }

    /**
     * Every author id named by an `@[ai-text]` block anywhere in a (possibly deeply nested) content value,
     * de-duplicated. Each STRING leaf goes through the shared resolver scanner — which descends into nested
     * ai-text prompts and if-block branches and honors the ai-text depth cap — so this collects exactly the
     * blocks the resolver could actually execute, and nothing else.
     *
     * @return array<int, string>
     */
    private function authorIdsIn(mixed $content): array
    {
        if (is_array($content)) {
            $ids = [];

            foreach ($content as $value) {
                foreach ($this->authorIdsIn($value) as $id) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        if (!is_string($content) || $content === '') {
            return [];
        }

        return $this->resolver->collectAiTextAuthorIds($content);
    }
}
