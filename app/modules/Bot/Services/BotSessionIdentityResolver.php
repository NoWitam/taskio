<?php

namespace App\Modules\Bot\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Bot\Models\Bot;
use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Bot-side implementation of the Generator {@see SessionAuthorIdentityResolver} contract (bound in the
 * Bot provider): the AUTHOR a `generate_content` step delegates its session to IS a bot, and what it lends
 * that session is exactly what a HUMAN delegation lends — composed by the shared
 * {@see BotDelegationIdentityComposer}, not forked, so an automated post and a hand-delegated one speak in
 * the same voice and draw the same face.
 *
 * It is the deliberate one-way Bot → Generator edge in its purest form (the mirror of
 * {@see BotAuthorVoiceResolver} over Variables): the lower Generator layer names only its own contract, and
 * the module that owns bots plugs the concrete in. Registered from the BOT provider with an UNCONDITIONAL
 * bind, while the Generator default uses bindIf — so neither side can clobber the other and the winner does
 * not depend on provider load ORDER (pinned by BotModuleBoundaryTest).
 *
 * TENANT BOUNDARY — the same posture as {@see BotAuthorVoiceResolver}: the workspace is pinned EXPLICITLY
 * from the caller rather than left to the ambient {@see WorkspaceScope}, because this seam is reachable from
 * a QUEUED run where that scope is a documented NO-OP (no active workspace) and an ambient-only lookup would
 * run UNCONSTRAINED and resolve a FOREIGN bot's identity. In own-database mode the caller carries no
 * workspace id (tenant tables omit the column) and the dedicated connection IS the boundary, so the
 * predicate is only added when an id is actually supplied. The ambient scope, when active, stays on top —
 * the two can only ever NARROW the result.
 *
 * MISSING WORKSPACE IS REFUSED, NOT WIDENED. "No workspace id" is legitimate ONLY in own-database mode; in
 * shared mode it would mean a query with NO tenant predicate at all — every workspace's bots — which is the
 * leak the explicit id exists to prevent. So with no id and no own-database mode ({@see TenantContext::isOwn},
 * the SAME mode test `TenantAware` routes the connection by) the lookup is refused. Unlike the voice
 * resolver, that refusal is fail-CLOSED at the CALLER: it does not cost a tone, it stops the generation —
 * which is the correct direction when the alternative is publishing under the wrong author.
 *
 * BOT STATUS IS NOT A FILTER, matching the voice resolver: an author is authored configuration, not an
 * execution capability, so pausing a bot must not silently change (or stop) content a workflow was told to
 * publish in its name.
 *
 * `identityFor` NEVER THROWS (the contract's clause, upheld here rather than assumed): a malformed id is
 * dropped BEFORE the query, and any throwable from the lookup or the composition degrades to `null`. Only the
 * exception's CLASS and the two ids are logged — never its message (a QueryException's carries its BINDINGS)
 * and never anything the composition handled (the likeness bytes, the opaque voice).
 *
 * `knowsAuthor` is the WRITE-side half of the same seam and is deliberately NOT wrapped that way: it composes
 * nothing, so the only failure left to swallow would be the lookup itself, and swallowing it is what turned a
 * storage outage into "this bot is not available in this workspace" on a workflow author's save. Both answers
 * come out of ONE lookup ({@see locate}) so they can never disagree about which authors this workspace has.
 */
class BotSessionIdentityResolver implements SessionAuthorIdentityResolver
{
    public function __construct(
        private BotDelegationIdentityComposer $identity,
        private TenantContext $tenant,
    ) {}

    /**
     * @return array{author: array{id: string, name: string, icon: ?string}, voice: ?string, visual: ?array<string, mixed>, character_image_bytes: ?string}|null
     */
    public function identityFor(string $botId, ?string $workspaceId): ?array
    {
        try {
            $bot = $this->locate($botId, $workspaceId);

            return $bot === null ? null : $this->identity->compose($bot);
        } catch (Throwable $e) {
            // "Never throws" is a contract clause, not a language guarantee: a severed connection, a
            // deadlock or an unreadable blob would all surface from here. Degrading to null hands the
            // caller the same outcome an unknown author already has — and since that outcome is a REFUSAL
            // to generate, the failure stays loud where it matters (the run stops) without this seam
            // inventing an exception type its callers do not handle.
            //
            // IT IS LOGGED, though, because the refusal PROSE lies in this one case: the RUN reports that the
            // bot is unavailable when the truth is that the database or the storage was — a lie worth keeping
            // only because the alternative (publishing under an author this seam could not assemble) is
            // worse, and only for a run. A SAVE no longer passes through here at all; it asks `knowsAuthor`,
            // which has nothing to swallow and therefore nothing to misreport. The exception
            // CLASS plus the two ids is all that is recorded — never the message (a QueryException's
            // message carries its bindings) and never anything the composition touched (the opaque voice,
            // the likeness bytes), so this stays inside the no-sensitive-data rule while making an
            // otherwise invisible infrastructure failure diagnosable.
            Log::warning('The session-author identity could not be resolved; the delegation was refused.', [
                'bot_id' => $botId,
                'workspace_id' => $workspaceId,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * The WRITE-side existence probe (see the contract for why it is a separate question): the SAME lookup,
     * and nothing after it — no composer, no Storage read.
     *
     * DELIBERATELY UNGUARDED. `identityFor`'s catch-all exists to honour a "never throws" clause and to keep
     * a RUN's refusal fail-closed; here it would do the opposite of its job. The only caller is a form
     * request, and turning a severed connection into `false` there would render the message "this bot is not
     * available in this workspace" about a bot that is perfectly available — the exact lie this method was
     * split out to stop telling. A database failure is allowed to surface as one.
     */
    public function knowsAuthor(string $botId, ?string $workspaceId): bool
    {
        return $this->locate($botId, $workspaceId) !== null;
    }

    /**
     * THE ONE LOOKUP both answers are derived from — the id guard, the tenant posture and the query, in a
     * single place so the write-time verdict and the run-time verdict cannot drift apart. Restating the
     * predicates in the probe would let a save accept an author the run then refuses (a definition that fails
     * every single time) or reject one the run would have accepted.
     *
     * Null covers all three refusals identically: a malformed id, a missing workspace outside own-database
     * mode, and "no such author in this workspace".
     */
    private function locate(string $botId, ?string $workspaceId): ?Bot
    {
        // The column is a uuid, so garbage would be a database error rather than a clean refusal.
        if (!Str::isUuid($botId)) {
            return null;
        }

        $pinned = is_string($workspaceId) && $workspaceId !== '';

        if (!$pinned && !$this->tenant->isOwn()) {
            return null;
        }

        $query = Bot::query();

        if ($pinned) {
            $query->where($query->getModel()->qualifyColumn(WorkspaceScope::COLUMN), $workspaceId);
        }

        return $query->find($botId);
    }
}
