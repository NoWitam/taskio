<?php

namespace App\Modules\Variables\Contracts;

/**
 * The AUTHOR-voice lookup seam: turns the author ids carried by `@[ai-text]` blocks into the OPAQUE voice
 * directives the shared ai-text agent writes in. Like {@see AiTextGenerator} it is a Variables-side
 * CONTRACT so the lower layer never names the module that owns authors (today: Bot) — the dependency
 * direction stays one-way, and Variables keeps knowing nothing about what an "author" actually is.
 *
 * The single method is a BATCH lookup on purpose: a recipe is scanned ONCE for every author id it mentions
 * and resolved in ONE query, so a per-block author can never turn into an N+1 across a run.
 *
 * FAIL-SAFE, NOT FAIL-CLOSED — the whole point of the design: an id that cannot be resolved (unknown,
 * soft-deleted, belonging to ANOTHER workspace, or simply malformed) is JUST ABSENT from the returned map.
 * Not null, not an empty string, not an exception: absent. The caller then falls back to the session voice
 * and finally to the block's persona tone, so a deleted author degrades a run's TONE and never breaks it.
 *
 * $workspaceId is EXPLICIT rather than ambient because this is reachable from a QUEUED run, where
 * {@see \App\Models\Scopes\WorkspaceScope} is a documented NO-OP (no active workspace) and an
 * ambient-only lookup would run UNCONSTRAINED and resolve a FOREIGN author. Pass the id the owning record
 * carries (null in own-database mode, where the dedicated connection IS the tenant boundary).
 *
 * The returned strings are OPAQUE authored config that lands in the agent's SYSTEM instruction (exactly
 * where the persona tone line sits). They are TRUSTED and must NEVER be logged.
 *
 * THE INPUT, BY CONTRAST, IS UNTRUSTED. The ids come from `@[ai-text]` payloads inside authored content —
 * editor-written, but equally API-written or imported — so an implementation MUST VALIDATE each id against
 * the shape its own storage uses (and DROP what does not fit) BEFORE it reaches a query. This is a clause of
 * the CONTRACT and not a detail of any one implementation, because it is what keeps a hostile id from being
 * an implementation's problem at all: the collector deliberately does not validate (it reports what the
 * content says, NUL bytes and all), and "unresolvable ⇒ absent" already covers dropping garbage, so a
 * refusal costs nothing while forwarding one costs a database error where a clean fail-safe was promised.
 */
interface AuthorVoiceResolver
{
    /**
     * Resolve the given author ids to their opaque voice directives in ONE lookup. NEVER throws.
     *
     * @param  array<int, string>  $authorIds  the ids collected from a recipe's ai-text blocks (may be empty,
     *                                         may contain duplicates or garbage)
     * @param  string|null  $workspaceId  the EXPLICIT tenant boundary (null = own-database mode)
     * @return array<string, string> authorId => opaque voice; UNRESOLVABLE ids are simply ABSENT
     */
    public function voicesFor(array $authorIds, ?string $workspaceId): array;
}
