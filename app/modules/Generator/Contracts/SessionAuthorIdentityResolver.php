<?php

namespace App\Modules\Generator\Contracts;

/**
 * The SESSION-AUTHOR lookup seam: turns an author id carried by an AUTOMATION's configuration into the
 * PRIMITIVES {@see \App\Modules\Generator\Services\SessionDelegationService::applyDelegation} stamps onto a
 * generation session — the author snapshot, the opaque voice, the frozen LOOK and the character's reference
 * bytes. It is the exact mirror of {@see \App\Modules\Variables\Contracts\AuthorVoiceResolver}, one layer up:
 * a Generator-side CONTRACT so this module never names the module that owns authors (today: Bot), which is
 * what keeps that dependency one-way (pinned by GeneratorModuleBoundaryTest).
 *
 * WHY A CONTRACT AND NOT A CALL. Delegating a session to an author is already implemented — the interactive
 * path composes exactly these primitives and hands them to the same seam. An automated caller (a workflow's
 * `generate_content` step) needs the same thing, but it lives in a PEER module that may name neither this
 * module's author-owner nor be named by it. Inverting the lookup is what lets the automation order an
 * identity it is not allowed to know the shape of.
 *
 * `identityFor` NEVER THROWS. Every failure mode — an unknown id, a soft-deleted author, one belonging to
 * ANOTHER workspace, a malformed id, or an infrastructure error while composing — answers `null`. An
 * implementation MUST validate the id against its own storage shape BEFORE it reaches a query (the ids come
 * from authored automation configuration, which is editor-written but equally API-written or imported, so a
 * hostile id must never become a database error where a clean refusal was promised).
 *
 * `null` IS FAIL-CLOSED HERE, and deliberately so — the opposite of the voice resolver's fail-SAFE posture.
 * An unresolvable AUTHOR VOICE costs a block its tone; an unresolvable SESSION AUTHOR means the automation
 * would publish content in nobody's name and with nobody's face, which is not a degraded version of what was
 * ordered. The CALLER is what makes that decision (this contract only reports "no identity"), and today's
 * caller refuses to generate at all.
 *
 * $workspaceId is EXPLICIT rather than ambient because this is reachable from a QUEUED run, where
 * {@see \App\Models\Scopes\WorkspaceScope} is a documented NO-OP (no active workspace) and an ambient-only
 * lookup would run UNCONSTRAINED and resolve a FOREIGN author. Pass the id the owning record carries — null
 * ONLY in own-database mode, where the dedicated connection IS the tenant boundary. An implementation must
 * REFUSE (return null) rather than WIDEN when it is handed no id outside that mode: "no predicate at all"
 * means every workspace's authors, which is exactly the leak the explicit id exists to prevent.
 *
 * THE RETURNED STRINGS ARE OPAQUE. `voice` is authored configuration that lands in an agent's SYSTEM
 * instruction and `character_image_bytes` is raw image content; both are TRUSTED, and NEITHER — nor any
 * `visual` field — is ever logged. Bytes are returned ONLY when the author's visual module is ENABLED and it
 * has an approved likeness whose blob is readable; anything less answers `null` for that one field while the
 * rest of the identity still resolves (a missing likeness is not a missing author).
 */
interface SessionAuthorIdentityResolver
{
    /**
     * Resolve ONE author to the delegation primitives, or null when it cannot be resolved. NEVER throws.
     *
     * @param  string  $botId  the author id an automation's configuration names (untrusted)
     * @param  string|null  $workspaceId  the EXPLICIT tenant boundary (null = own-database mode only)
     * @return array{author: array{id: string, name: string, icon: ?string}, voice: ?string, visual: ?array<string, mixed>, character_image_bytes: ?string}|null
     */
    public function identityFor(string $botId, ?string $workspaceId): ?array;

    /**
     * Does this workspace KNOW the named author? A cheap EXISTENCE probe: the same id + workspace predicate
     * {@see identityFor} starts with, and nothing after it — no voice, no frozen look, no likeness bytes.
     *
     * WHY THIS IS NOT JUST `identityFor(…) !== null`. The two answer different QUESTIONS, and a caller that
     * only wants the second one must not be forced to pay for — or be lied to by — the first:
     *
     *   IT MUST NOT PAY. The only caller is a WRITE path (a workflow author saving a definition that names a
     *   `generate_content` author). Composing an identity reads an image blob out of Storage, and a save has
     *   no use whatsoever for those bytes; the run that eventually needs them composes its own, later, from
     *   the state of the world AT RUN TIME. Reading them at save time was pure waste.
     *
     *   IT MUST NOT LIE. `identityFor` folds EVERY failure into one `null` — including an infrastructure
     *   failure while composing — which is the correct fail-CLOSED posture for a RUN (it refuses to publish
     *   under an author it could not assemble). Reused as a write-side existence check, that same fold turns
     *   "the storage was briefly unreachable" into the validation message "this bot is not available in this
     *   workspace": an author who cannot save, blamed on a bot that is perfectly fine. This probe reports
     *   only what it actually determined.
     *
     * THE PREDICATE MUST NOT DRIFT from `identityFor`'s. An implementation MUST derive both verdicts from ONE
     * lookup rather than restate the rules, or a save could accept an author the run would then refuse (a
     * definition that fails every single time) — or reject one the run would have accepted.
     *
     * SAME TENANT POSTURE, SAME REFUSAL: $workspaceId is the explicit boundary described above, a malformed
     * id is `false`, and "no workspace id outside own-database mode" is REFUSED (`false`), never widened.
     *
     * UNLIKE `identityFor`, THIS MAY THROW. It has no composition to swallow, so the only thing left that can
     * fail is the lookup itself — a severed connection, a deadlock. Reporting that as `false` would recreate
     * the very lie this method exists to remove, so an infrastructure error is allowed to surface as an
     * infrastructure error (a 500 on the write path) instead of being dressed as a rejected author.
     *
     * @param  string  $botId  the author id an automation's configuration names (untrusted)
     * @param  string|null  $workspaceId  the EXPLICIT tenant boundary (null = own-database mode only)
     */
    public function knowsAuthor(string $botId, ?string $workspaceId): bool;
}
