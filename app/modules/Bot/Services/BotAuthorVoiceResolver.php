<?php

namespace App\Modules\Bot\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Bot\Models\Bot;
use App\Modules\Variables\Contracts\AuthorVoiceResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * The Bot-side implementation of the Variables {@see AuthorVoiceResolver} contract (bound in the Bot
 * provider): a per-block ai-text AUTHOR is a BOT, and its voice is the same opaque directive
 * {@see BotVoiceComposer} already builds for a delegated session — composed here, not forked, so an
 * author-authored block and a bot-delegated session speak in exactly the same voice.
 *
 * It is the deliberate one-way Bot → Variables edge (the mirror of the Bot → Generator delegation edge):
 * the lower Variables layer names only its own contract, and the module that owns bots plugs the concrete
 * in. Registered from the BOT provider with an UNCONDITIONAL bind, while the Variables default uses bindIf —
 * so neither side can clobber the other and the winner does not depend on provider load ORDER (pinned by
 * BotModuleBoundaryTest).
 *
 * ONE QUERY for all ids: a recipe's authors are collected once and resolved in a single `whereIn`, so a
 * per-block author can never degenerate into an N+1 across a run.
 *
 * TENANT BOUNDARY — the same posture as the Generator's file resolution
 * ({@see \App\Modules\Generator\Services\SessionDelegationService}): the workspace is pinned EXPLICITLY
 * from the caller rather than left to the ambient {@see WorkspaceScope}, because this seam is reachable
 * from a QUEUED run where that scope is a documented NO-OP (no active workspace) and an ambient-only
 * lookup would run UNCONSTRAINED and resolve a FOREIGN bot's voice. In own-database mode the caller
 * carries no workspace id (tenant tables omit the column) and the dedicated connection IS the boundary,
 * so the predicate is only added when an id is actually supplied. The ambient scope, when active, stays
 * on top — the two can only ever NARROW the result.
 *
 * MISSING WORKSPACE IS REFUSED, NOT WIDENED. "No workspace id" is only legitimate in OWN-database mode;
 * in shared mode it would mean a query with NO tenant predicate at all — every workspace's bots — which
 * is exactly the leak the explicit id exists to prevent. So when no id is supplied and the application
 * is not in own-database mode ({@see TenantContext::isOwn}, the SAME mode test `TenantAware` routes the
 * connection by), the lookup is refused with an EMPTY map. That is fail-SAFE, not fail-closed: an absent
 * id is the contract's documented outcome, so a caller that forgot the boundary loses the authored TONE
 * and still renders its text. The refusal lives HERE, in the one class that owns the predicate, rather
 * than in each caller — a future caller cannot forget it.
 *
 * FAIL-SAFE: nothing here throws or reports. A malformed id is dropped BEFORE the query (the column is a
 * uuid, so garbage would otherwise be a database error rather than a clean refusal), and an unknown,
 * soft-deleted or foreign bot is simply ABSENT from the map — the caller then falls back to the session
 * voice / persona tone. Bot STATUS is intentionally NOT a filter: a voice is authored configuration, not
 * an execution capability, so pausing a bot must not silently re-tone published recipes.
 *
 * The composed directives are opaque authored config and are NEVER logged (nor is any id).
 */
class BotAuthorVoiceResolver implements AuthorVoiceResolver
{
    public function __construct(
        private BotVoiceComposer $composer,
        private TenantContext $tenant,
    ) {}

    /**
     * @param  array<int, string>  $authorIds
     * @return array<string, string>
     */
    public function voicesFor(array $authorIds, ?string $workspaceId): array
    {
        $ids = $this->uuidsOnly($authorIds);

        if ($ids === []) {
            return [];
        }

        $pinned = is_string($workspaceId) && $workspaceId !== '';

        // No workspace + not own-database mode ⇒ there would be NO tenant predicate. Refuse (see the
        // class docblock): losing a tone is acceptable, reading another workspace's bots is not.
        if (!$pinned && !$this->tenant->isOwn()) {
            return [];
        }

        $query = Bot::query()->whereIn(Bot::query()->getModel()->getQualifiedKeyName(), $ids);

        if ($pinned) {
            $query->where($query->getModel()->qualifyColumn(WorkspaceScope::COLUMN), $workspaceId);
        }

        $voices = [];

        foreach ($query->get() as $bot) {
            $voice = $this->composer->compose($bot);

            foreach ($this->spellingsOf($bot->getKey(), $ids) as $spelling) {
                $voices[$spelling] = $voice;
            }
        }

        return $voices;
    }

    /**
     * The id spellings the CALLER actually asked with that denote $key — normally exactly one, and identical
     * to $key.
     *
     * The map is keyed the way the CALLER wrote the id, NOT the way the database echoes it back, because a
     * uuid column matches CASE-INSENSITIVELY: an `@[ai-text]` block carrying an UPPERCASE author id (an
     * API-created template, an import) resolves a row and IS PAID FOR, and would then MISS in
     * {@see \App\Modules\Variables\Support\AiVoiceContext::effectiveDirective}, which looks the id up exactly
     * as its own directive spells it — a paid query whose voice is silently discarded. Normalizing the case
     * on both sides instead would push a rule about id BYTES into the Variables layer for no gain.
     *
     * ALL matching spellings are returned, not just the first: two blocks may name the same author in
     * different case, and each looks itself up by its own bytes, so both must be voiced.
     *
     * @param  array<int, string>  $ids  the caller's ids, already uuid-shaped + de-duplicated
     * @return array<int, string>
     */
    private function spellingsOf(mixed $key, array $ids): array
    {
        $needle = strtolower((string) $key);
        $spellings = array_values(array_filter($ids, fn (string $id): bool => strtolower($id) === $needle));

        // Unreachable in practice (the row came back BECAUSE one of $ids matched it); keeps the map keyed by
        // a real id rather than silently dropping a voice should that ever stop holding.
        return $spellings === [] ? [(string) $key] : $spellings;
    }

    /**
     * The DE-DUPLICATED, uuid-SHAPED subset of the given ids — the only ones worth a query. Anything else
     * (a non-string, an empty string, a slug, a truncated id) can never match a uuid primary key, so
     * dropping it here costs nothing and keeps a malformed payload from reaching the database at all.
     *
     * @param  array<int, string>  $authorIds
     * @return array<int, string>
     */
    private function uuidsOnly(array $authorIds): array
    {
        $ids = array_filter($authorIds, fn ($id): bool => is_string($id) && Str::isUuid($id));

        return array_values(array_unique($ids));
    }
}
