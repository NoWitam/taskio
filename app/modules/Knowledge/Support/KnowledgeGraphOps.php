<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;

/**
 * WHAT THE COMPOSER PROPOSED TO DO TO THE GRAPH, after everything untrustworthy has been taken out of
 * it.
 *
 * SECURITY BOUNDARY: {@see fromArray} is the only way in, and it is deliberately paranoid in the same
 * shape the creative-direction laundering upstream is — iteration over KNOWN keys (an unknown key does
 * not exist rather than being rejected), types checked, lists bounded, strings stripped of control
 * characters, fence markers scrubbed, and every field length-capped.
 *
 * ------------------------------------------------------------------------------------------------
 * THE GRANULARITY OF REFUSAL IS THE DESIGN
 *
 * Three levels, and each is chosen against what its failure costs:
 *
 *   ONE FIELD dropped        a malformed date. The statement is still true without it, and refusing
 *                            the whole operation over a formatting slip throws away a fact.
 *   ONE OPERATION dropped    an invented handle, a verb outside the vocabulary, a self-loop, a
 *                            duplicate, a properties bag that does not fit its type — and ALWAYS a
 *                            delete or a retract. The rest of the answer is good work.
 *   THE WHOLE OBJECT         template syntax anywhere in it, or nothing survived. Directives are the
 *                            one thing the store refuses on every path, and a set with nothing left
 *                            is not a proposal.
 *
 * ------------------------------------------------------------------------------------------------
 * EVERY REFUSAL IS REPORTED
 *
 * `$rejected` and `$warnings` are not diagnostics — they are the product. A vocabulary gap that
 * silently swallows every `mentored` relation will never be discovered; the same gap reported as
 * "3 operations dropped: type `mentored` is not in this base's vocabulary" is the evidence for adding
 * it. The same argument holds for a matrix refusal, a duplicate, and a rewrite that lost a wikilink.
 *
 * ------------------------------------------------------------------------------------------------
 * THERE IS NO DELETE, AND THAT IS NOT AN OMISSION
 *
 * `delete` and `retract` are dropped whatever they name, including when they are well-formed. A model
 * that can retract statements can quietly empty a base, and no amount of reviewing catches an absence
 * — a reviewer sees the operations that ARE there. Ending a relation (`end`, with a date) is the
 * expressive equivalent for everything legitimate, and it keeps the history.
 */
final class KnowledgeGraphOps
{
    /** Ops the model may name for a RELATION. `delete`/`retract` are absent on purpose. */
    public const RELATION_OPS = ['create', 'update', 'end'];

    /** Ops the model may name for an ENTITY's content. */
    public const WIKI_OPS = ['create', 'append', 'rewrite'];

    // --- rejection codes (the report's own vocabulary; the client words them) --------------
    public const REJECT_UNKNOWN_HANDLE = 'unknown_handle';

    public const REJECT_UNKNOWN_TYPE = 'unknown_relation_type';

    public const REJECT_TYPE_NOT_ALLOWED = 'type_not_allowed';

    public const REJECT_SELF_LOOP = 'self_loop';

    public const REJECT_FORBIDDEN_OP = 'forbidden_op';

    public const REJECT_UNKNOWN_OP = 'unknown_op';

    public const REJECT_PROPERTIES = 'properties_refused';

    public const REJECT_DUPLICATE = 'duplicate_relation';

    public const REJECT_PAIR = 'pair_refused';

    public const REJECT_CAP = 'op_cap_reached';

    /**
     * `valid_to` before `valid_from`.
     *
     * THE SAME NAME THE WRITE PATH USES ({@see \App\Modules\Knowledge\Exceptions\KnowledgeRelationRefused::DATES}
     * and the `knowledge.relations.dates_reversed` message), because it is the same rule. A reader who
     * meets it in a preview and again in a refusal should not have to work out that the two are
     * related — and a client can key both off one string.
     */
    public const REJECT_DATES = 'dates_reversed';

    public const REJECT_DIRECTIVE = 'template_directive';

    public const REJECT_MALFORMED = 'malformed';

    // --- warning codes ---------------------------------------------------------------------
    //
    // ONE RULE, ONE CHANNEL. What lives here is the GRAPH half — what laundering did to the relations.
    // What the server did to a proposal's TEXT (a rewrite degraded to an append, a rewrite that drops
    // wikilinks) belongs to the RUN NOTES, and is emitted there by KnowledgeDraftService as
    // DraftRunNotes::AMEND_APPEND_ONLY and ::AMEND_LINKS_LOST. Two constants here once duplicated those
    // — one of them character for character — and emitted nothing. Removed rather than left as a second
    // place a reader would reasonably expect the same fact to appear.
    public const WARN_UNTYPED_PAIR = 'pair_unchecked';

    public const WARN_AMBIGUOUS_UNRESOLVED = 'ambiguity_unresolved';

    /**
     * A content change aimed at an EXISTING entry was turned into a shadow draft, so a human sees it
     * before it lands. Reported so the ops panel can say the proposal moved rather than vanished.
     */
    public const WARN_MOVED_TO_REVIEW = 'moved_to_review';

    /**
     * A `create` named a `replaces` handle that this answer does not end.
     *
     * The FIELD is dropped, not the operation: a new relation is a perfectly good statement on its own,
     * and refusing it because its footnote was wrong would throw away a fact to punish an annotation.
     * The reviewer is still told, because the model believed it was recording a REPLACEMENT and what
     * will actually be stored is an unrelated new relation.
     */
    public const WARN_REPLACES_UNBOUND = 'replaces_unbound';

    private const MAX_DESCRIPTION = 300;

    private const MAX_TITLE = 255;

    private const MAX_PROPERTY_KEYS = 4;

    private const MAX_PROPERTY_KEY_CHARS = 40;

    private const MAX_PROPERTY_VALUE_CHARS = 200;

    /**
     * How many entities ONE answer may declare.
     *
     * CONFIGURED, and aligned with the draft cap. It was a hard-coded 20 here while
     * `drafting.max_entries_per_session` was 8, so the two channels that both end up creating entries
     * disagreed by more than a factor of two — and the looser of them was the one with no review card
     * of its own. A run that wants to add twenty new pages has misread the material, which is the same
     * judgement the draft cap already encodes.
     */
    private static function maxNewEntities(): int
    {
        return max(1, (int) config(
            'knowledge.drafting.max_new_entities',
            config('knowledge.drafting.max_entries_per_session', 8),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entities  new entities the run declared (`N<n>`)
     * @param  array<int, array<string, mixed>>  $wikiUpdates
     * @param  array<int, array<string, mixed>>  $graphUpdates
     * @param  array<int, array<string, mixed>>  $unresolved  the model's own "I could not tell" notes
     * @param  array<int, array<string, mixed>>  $rejected
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private function __construct(
        public readonly array $entities,
        public readonly array $wikiUpdates,
        public readonly array $graphUpdates,
        public readonly array $unresolved,
        public readonly array $rejected,
        public readonly array $warnings,
    ) {}

    public static function none(): self
    {
        return new self([], [], [], [], [], []);
    }

    public function isEmpty(): bool
    {
        return $this->entities === [] && $this->wikiUpdates === [] && $this->graphUpdates === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entities' => $this->entities,
            'wiki_updates' => $this->wikiUpdates,
            'graph_updates' => $this->graphUpdates,
            'unresolved' => $this->unresolved,
            'rejected' => $this->rejected,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Launder one model-written graph proposal against what is actually true.
     *
     * Never throws and never returns null: an unusable answer is an EMPTY set with the reasons in
     * `$rejected`, because the caller's job is to tell a human what happened, and an exception at this
     * layer would be a 500 on a session the user is watching.
     */
    public static function fromArray(mixed $raw, GraphOpsContext $context, TemplateDirectiveGuard $guard): self
    {
        if (!is_array($raw)) {
            return self::none();
        }

        // FAIL-CLOSED ON DIRECTIVES, over the whole object at once. An entry is data that other
        // features inject into prompts, so smuggled template syntax is the one thing refused on every
        // path — and checking the serialized whole means a directive cannot hide in a key, a nested
        // property or a field this method does not otherwise read.
        $serialized = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (is_string($serialized) && $guard->violation($serialized) !== null) {
            return new self([], [], [], [], [[
                'code' => self::REJECT_DIRECTIVE,
                'scope' => 'response',
            ]], []);
        }

        $rejected = [];
        $warnings = [];

        $entities = self::entities($raw['entities'] ?? null, $context, $rejected);
        $wiki = self::wikiUpdates($raw['wiki_updates'] ?? null, $context, $entities, $rejected, $warnings);
        $graph = self::graphUpdates($raw['graph_updates'] ?? null, $context, $entities, $rejected, $warnings);
        $unresolved = self::unresolved($raw['unresolved'] ?? null);

        self::reportUnansweredAmbiguity($context, $graph, $wiki, $warnings);

        return new self($entities, $wiki, $graph, $unresolved, $rejected, $warnings);
    }

    // ---- new entities -------------------------------------------------------------

    /**
     * `N<n>` declarations — things the material named that the base does not have.
     *
     * They are usable IMMEDIATELY by the operations below, which is what lets one answer create a
     * person and relate them without a round trip. Nothing is written here: this is a PROPOSAL, and a
     * human accepts it.
     *
     * @param  array<int, array<string, mixed>>  $rejected
     * @return array<int, array<string, mixed>>
     */
    private static function entities(mixed $raw, GraphOpsContext $context, array &$rejected): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $clean = [];
        $seen = [];

        $cap = self::maxNewEntities();

        foreach ($raw as $entity) {
            if (!is_array($entity)) {
                $rejected[] = ['code' => self::REJECT_MALFORMED, 'scope' => 'entities'];

                continue;
            }

            // THE OVERFLOW IS REPORTED. It used to `continue` in silence, which broke this class's one
            // rule — every refusal is news the reviewer is given — in the case where it matters most: a
            // run that declared thirty people had the last ten dropped without a trace, and the
            // relations naming them then failed with a reason ("dependency not accepted") that pointed
            // at the reviewer rather than at the cap.
            if (count($clean) >= $cap) {
                $rejected[] = ['code' => self::REJECT_CAP, 'scope' => 'entities', 'max' => $cap];

                continue;
            }

            $ref = self::handle($entity['ref'] ?? null, 'N');
            $title = self::text($entity['title'] ?? null, self::MAX_TITLE);

            if ($ref === null || $title === null) {
                $rejected[] = ['code' => self::REJECT_MALFORMED, 'scope' => 'entities'];

                continue;
            }

            // A `N<n>` that collides with an existing handle, or with another declaration, is an
            // address that would mean two things — dropped rather than silently renumbered.
            if (isset($seen[$ref]) || $context->hasEntity($ref)) {
                $rejected[] = ['code' => self::REJECT_MALFORMED, 'scope' => 'entities', 'ref' => $ref];

                continue;
            }

            $seen[$ref] = true;

            $clean[] = [
                'ref' => $ref,
                'title' => $title,
                // The slug is a SUGGESTION here; the entry service mints the real one on acceptance,
                // which is the only place that can guarantee it is unique in the base.
                'slug' => self::text($entity['slug'] ?? null, 200),
                'entry_type' => self::entryType($entity['type'] ?? null),
                'aliases' => self::aliases($entity['aliases'] ?? null),
                // THE DRAFT THIS HANDLE ALREADY IS. Server-written (see
                // KnowledgeDraftService::withDeclaredEntities()), never from the model — which is why
                // it is whitelisted through rather than validated: a model-supplied value here would
                // let an answer point a handle at somebody else's entry by naming its slug. Anything
                // arriving from a model is dropped by this `is_string` check landing on nothing.
                'from_draft_slug' => is_string($entity['from_draft_slug'] ?? null)
                    ? mb_substr($entity['from_draft_slug'], 0, 200)
                    : null,
            ];
        }

        return $clean;
    }

    // ---- wiki updates -------------------------------------------------------------

    /**
     * Content operations against an entity — the prose half of the proposal.
     *
     * `rewrite` is the dangerous one and carries two guards, both inherited rather than invented:
     * an entity the composer was shown only IN PART may not be rewritten at all (degraded to `append`,
     * the G1 rule, applied here because this path reaches the same entries by a different route), and
     * a rewrite that drops a `[[wikilink]]` the current text carries is allowed through WITH A WARNING
     * naming every lost link. The owner chose review-for-everything, so a human will see it — but a
     * silently thinner graph is exactly what a reviewer skims past, so it has to be stated.
     *
     * @param  array<int, array<string, mixed>>  $entities
     * @param  array<int, array<string, mixed>>  $rejected
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function wikiUpdates(mixed $raw, GraphOpsContext $context, array $entities, array &$rejected, array &$warnings): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $cap = max(1, (int) config('knowledge.relations.max_ops_per_session'));
        $newRefs = array_column($entities, 'ref');
        $clean = [];

        foreach ($raw as $update) {
            if (count($clean) >= $cap) {
                $rejected[] = ['code' => self::REJECT_CAP, 'scope' => 'wiki_updates', 'max' => $cap];

                break;
            }

            if (!is_array($update)) {
                continue;
            }

            $handle = self::text($update['entity'] ?? null, 16);
            $op = self::text($update['op'] ?? null, 16);
            $content = self::text($update['content'] ?? null, (int) config('knowledge.entry_max_chars'));

            if ($handle === null || $op === null || $content === null) {
                $rejected[] = ['code' => self::REJECT_MALFORMED, 'scope' => 'wiki_updates'];

                continue;
            }

            if (!in_array($op, self::WIKI_OPS, true)) {
                $rejected[] = ['code' => self::REJECT_UNKNOWN_OP, 'scope' => 'wiki_updates', 'op' => $op];

                continue;
            }

            $isNew = in_array($handle, $newRefs, true);
            $entity = $context->entity($handle);

            if (!$isNew && $entity === null) {
                $rejected[] = ['code' => self::REJECT_UNKNOWN_HANDLE, 'scope' => 'wiki_updates', 'entity' => $handle];

                continue;
            }

            if (!$isNew) {
                // AN EXISTING ENTITY'S TEXT IS NOT CHANGED FROM HERE ANY MORE.
                //
                // It used to be, and that was the hole. This channel reached the graph applier, which
                // resolved the entity by slug and wrote whenever ANY draft in the session was accepted
                // — no card, no diff, no chance to refuse, and the reviewer learned about it from
                // `updated[]` afterwards. Meanwhile the SAME conceptual operation arriving as
                // `entries[].action=update` became a shadow draft with a card, a diff and a frozen
                // revision. One path walked around the other's safeguards.
                //
                // "Change an existing entry's text" now has ONE mechanism, the reviewed one:
                // {@see \App\Modules\Knowledge\Services\KnowledgeDraftService::absorbWikiUpdates()} has
                // already turned this operation into a shadow draft. Recorded rather than dropped in
                // silence, so the ops report can say the proposal moved rather than vanished.
                $warnings[] = [
                    'code' => self::WARN_MOVED_TO_REVIEW,
                    'entity' => $handle,
                    'title' => $entity['title'] ?? '',
                ];

                continue;
            }

            // What remains is a NEW entity's own initial content; it has nothing to append to and no
            // revision to be locked against, because there is no prior version of it anywhere.
            //
            // The truncation rule, the server-stamped optimistic lock and the lost-link guard all moved
            // out with the existing-entity branch above: they are properties of AMENDING something that
            // exists, and the shadow-draft path now owns every one of them.
            $clean[] = [
                'entity' => $handle,
                'op' => 'create',
                'section' => null,
                'content' => $content,
            ];
        }

        return $clean;
    }

    // ---- graph updates ------------------------------------------------------------

    /**
     * Relation operations — the typed half.
     *
     * @param  array<int, array<string, mixed>>  $entities
     * @param  array<int, array<string, mixed>>  $rejected
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function graphUpdates(mixed $raw, GraphOpsContext $context, array $entities, array &$rejected, array &$warnings): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $cap = max(1, (int) config('knowledge.relations.max_ops_per_session'));

        // A NEW entity's type is the one declared for it in the same answer — legitimate, because it
        // is the only type that thing has. An EXISTING entity's type is the stored one and the model's
        // opinion is never consulted: the workspace has already decided.
        $newTypes = [];

        // The TITLES too, not only the types: a refusal that says "N6 → N7" is a refusal nobody can act
        // on. See createOp(), where a rejected pair is written out as a sentence.
        $newTitles = [];

        foreach ($entities as $entity) {
            $newTypes[$entity['ref']] = KnowledgeEntryType::tryFrom((string) ($entity['entry_type'] ?? ''));
            $newTitles[$entity['ref']] = is_string($entity['title'] ?? null) ? $entity['title'] : null;
        }

        // WHICH RELATIONS THIS ANSWER ENDS, collected before anything is judged.
        //
        // A pre-pass because a `create` may name its `replaces` before the `end` it refers to appears —
        // the model writes in whatever order it thinks, and the binding is a property of the SET, not of
        // the position. Resolving it as we went would accept or drop the same pair depending on how the
        // model happened to sequence it, which is the kind of rule nobody can reason about.
        $ended = [];

        foreach ($raw as $update) {
            if (is_array($update)
                && ($update['op'] ?? null) === 'end'
                && is_string($update['relation'] ?? null)
                && $context->relation(strtoupper(trim($update['relation']))) !== null) {
                $ended[] = strtoupper(trim($update['relation']));
            }
        }

        $clean = [];

        foreach ($raw as $update) {
            if (count($clean) >= $cap) {
                $rejected[] = ['code' => self::REJECT_CAP, 'scope' => 'graph_updates', 'max' => $cap];

                break;
            }

            if (!is_array($update)) {
                continue;
            }

            $op = self::text($update['op'] ?? null, 16);

            if ($op === null) {
                $rejected[] = ['code' => self::REJECT_MALFORMED, 'scope' => 'graph_updates'];

                continue;
            }

            // DROPPED ALWAYS, however well-formed. The contract does not contain these verbs, and a
            // model asking for one is a model that would use one — see the class docblock.
            if (in_array($op, ['delete', 'retract', 'remove', 'destroy'], true)) {
                $rejected[] = ['code' => self::REJECT_FORBIDDEN_OP, 'scope' => 'graph_updates', 'op' => $op];

                continue;
            }

            if (!in_array($op, self::RELATION_OPS, true)) {
                $rejected[] = ['code' => self::REJECT_UNKNOWN_OP, 'scope' => 'graph_updates', 'op' => $op];

                continue;
            }

            $operation = $op === 'create'
                ? self::createOp($update, $context, $newTypes, $newTitles, $ended, $rejected, $warnings)
                : self::existingOp($op, $update, $context, $rejected);

            if ($operation !== null) {
                $clean[] = $operation;
            }
        }

        return self::unbindDeadReplacements($clean, $warnings);
    }

    /**
     * Cut every `replaces` whose `end` DID NOT SURVIVE the laundering.
     *
     * The pre-pass that collects endings runs BEFORE anything is judged, so a `create` could keep a
     * binding to an `end` that was then dropped — by the type matrix, a cap, a duplicate, an unknown
     * handle. Applying that set wrote the new relation and left the old one ACTIVE: the base asserted
     * two contradictory facts at once, and nothing anywhere marked the contradiction.
     *
     * UNBOUND RATHER THAN REFUSED, on the same reasoning an unbound `replaces` gets elsewhere — a new
     * relation is a complete statement on its own, and dropping a fact because its footnote lost its
     * referent throws away more than it protects. The warning is what keeps that from being silent: the
     * run believed it was recording a replacement, and it is not.
     *
     * `pair_with` needs no clean-up. It is DERIVED from the surviving operations, so a vanished `end`
     * already leaves both sides unpaired.
     *
     * @param  array<int, array<string, mixed>>  $clean
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<int, array<string, mixed>>
     */
    private static function unbindDeadReplacements(array $clean, array &$warnings): array
    {
        $survived = [];

        foreach ($clean as $operation) {
            if (($operation['op'] ?? null) === 'end' && is_string($operation['relation'] ?? null)) {
                $survived[] = $operation['relation'];
            }
        }

        foreach ($clean as $index => $operation) {
            $replaces = $operation['replaces'] ?? null;

            if (is_string($replaces) && !in_array($replaces, $survived, true)) {
                $clean[$index]['replaces'] = null;
                $warnings[] = ['code' => self::WARN_REPLACES_UNBOUND, 'replaces' => $replaces];
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $update
     * @param  array<string, ?KnowledgeEntryType>  $newTypes  handles minted by this same answer
     * @param  array<int, string>  $ended  relation handles this same answer ends
     * @param  array<int, array<string, mixed>>  $rejected
     * @param  array<int, array<string, mixed>>  $warnings
     * @return array<string, mixed>|null
     */
    private static function createOp(array $update, GraphOpsContext $context, array $newTypes, array $newTitles, array $ended, array &$rejected, array &$warnings): ?array
    {
        $from = self::text($update['from'] ?? null, 16);
        $to = self::text($update['to'] ?? null, 16);
        $rawType = self::text($update['type'] ?? null, 40);

        if ($from === null || $to === null || $rawType === null) {
            $rejected[] = ['code' => self::REJECT_MALFORMED, 'scope' => 'graph_updates', 'op' => 'create'];

            return null;
        }

        if ($from === $to) {
            // A relation from a thing to itself is never a fact; it is a bug in whatever wrote it.
            $rejected[] = ['code' => self::REJECT_SELF_LOOP, 'from' => $from, 'to' => $to];

            return null;
        }

        foreach ([$from, $to] as $handle) {
            if (!array_key_exists($handle, $newTypes) && !$context->hasEntity($handle)) {
                $rejected[] = ['code' => self::REJECT_UNKNOWN_HANDLE, 'scope' => 'graph_updates', 'entity' => $handle];

                return null;
            }
        }

        $type = KnowledgeRelationType::tryFrom($rawType);

        if ($type === null) {
            // A GAP IN THE VOCABULARY, reported rather than swallowed. Silently dropping every
            // `mentored` is how a missing verb stays missing forever; reported, the same drop is the
            // evidence for adding one.
            $rejected[] = ['code' => self::REJECT_UNKNOWN_TYPE, 'type' => $rawType, 'from' => $from, 'to' => $to];

            return null;
        }

        if (!$context->allows($type)) {
            $rejected[] = ['code' => self::REJECT_TYPE_NOT_ALLOWED, 'type' => $type->value];

            return null;
        }

        [$properties, $offending] = self::properties($update['properties'] ?? null, $type);

        if ($offending !== null) {
            $rejected[] = ['code' => self::REJECT_PROPERTIES, 'type' => $type->value, 'property' => $offending];

            return null;
        }

        $validFrom = self::date($update['valid_from'] ?? null);
        $validTo = self::date($update['valid_to'] ?? null);

        // A RELATION CANNOT END BEFORE IT BEGINS — refused HERE, not only at the write.
        //
        // `date()` answers whether each date is well-formed and nothing more, so a reversed PAIR
        // (from 2026-07, to 2024-01) passed laundering intact, reached the review screen as an
        // operation to approve, and was refused only in `accept` — after the reviewer had chosen it.
        // The whole point of this stage is that a refusal is news the reviewer gets BEFORE deciding.
        //
        // The service keeps its own barrier: defence in depth for the human path and for anything that
        // does not pass through here.
        //
        // ONLY ON `create`, matching the service exactly. An `end` carries a closing date for a
        // relation whose `valid_from` this answer never saw, and a date before it means the START was
        // wrong rather than the ending — refusing would leave a statement nobody can retire. An
        // `update` never carries `valid_to` at all (see below). Diverging here would refuse in the
        // preview something the write path would accept, which is its own kind of lie.
        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            $rejected[] = [
                'code' => self::REJECT_DATES,
                'type' => $type->value,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ];

            return null;
        }

        // THE MATRIX, advisory exactly as the write path has it: a pair refused only when BOTH ends
        // are typed, a warning when either is unknown. Every entry written before `entry_type` existed
        // is untyped, and refusing on a guess would reject correct relations across a whole base.
        //
        // ------------------------------------------------------------------------------------------
        // A REVERSED PAIR IS REPORTED, NEVER SILENTLY REVERSED
        //
        // The tempting shortcut, considered and declined: when the pair is refused but the REVERSE
        // would be allowed and the verb is asymmetric, turn it round instead of dropping it.
        // "NetWatch member_of Agent K" has exactly one sensible reading, so why not just fix it.
        //
        // Because that is the server authoring a relation the model did not propose, and the failure is
        // asymmetric. When the guess is right it saves a click; when it is wrong it manufactures a
        // plausible fact in a direction nobody chose — and the review card would then render the
        // SERVER'S sentence, correct-looking, indistinguishable from the model's. A reviewer approving
        // it has no way to know which of the two they are agreeing with. That is precisely the failure
        // this module refuses everywhere else: a guess is a fact about the wrong subject, and nobody
        // ever finds it.
        //
        // So the refusal carries `reversed_would_be_valid` instead. The reviewer gets the whole insight
        // — "this is backwards" — and the base gets no statement its writer never made.
        $verdict = RelationVocabulary::checkTypes(
            $type,
            self::handleType($from, $context, $newTypes),
            self::handleType($to, $context, $newTypes),
        );

        if ($verdict === RelationVerdict::REFUSED) {
            $fromType = self::handleType($from, $context, $newTypes);
            $toType = self::handleType($to, $context, $newTypes);

            $rejected[] = [
                'code' => self::REJECT_PAIR,
                'type' => $type->value,
                'from' => $from,
                'to' => $to,
                // NAMES, NOT HANDLES. "pair_refused N6 → N7" is a sentence nobody can act on; the
                // reviewer needs to see WHICH subjects and WHY, in the same way `skipped[].missing`
                // names the draft that was not accepted, and for the same reason.
                'from_title' => self::handleTitle($from, $context, $newTitles),
                'to_title' => self::handleTitle($to, $context, $newTitles),
                'from_entry_type' => $fromType?->value,
                'to_entry_type' => $toType?->value,
                // THE SERVER DOES NOT REVERSE IT — see below — but it says when reversing would have
                // worked, which is what turns a refusal into something a person can fix in one move.
                'reversed_would_be_valid' => !$type->isSymmetric()
                    && RelationVocabulary::checkTypes($type, $toType, $fromType) === RelationVerdict::ALLOWED,
            ];

            return null;
        }

        if ($verdict === RelationVerdict::UNKNOWN_TYPES) {
            $warnings[] = [
                'code' => self::WARN_UNTYPED_PAIR,
                'type' => $type->value,
                'from' => $from,
                'to' => $to,
            ];
        }

        // The duplicate guard can only speak about ends that already exist; a new entity has no
        // relations yet by construction.
        $fromId = $context->entity($from)['id'] ?? null;
        $toId = $context->entity($to)['id'] ?? null;

        if ($fromId !== null && $toId !== null) {
            $existing = $context->duplicateOf($fromId, $toId, $type->value, $validFrom);

            if ($existing !== null) {
                $rejected[] = [
                    'code' => self::REJECT_DUPLICATE,
                    'type' => $type->value,
                    'existing_relation_id' => $existing,
                ];

                return null;
            }
        }

        return [
            'op' => 'create',
            'from' => $from,
            'to' => $to,
            'type' => $type->value,
            'description' => self::text($update['description'] ?? null, self::MAX_DESCRIPTION),
            'properties' => $properties,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            // THE BINDING: which relation this one replaces. Only ever a handle THIS answer also ends —
            // see replacesHandle() for why an unbound one loses the field rather than the operation.
            'replaces' => self::replacesHandle($update['replaces'] ?? null, $ended, $warnings),
        ];
    }

    /**
     * The `replaces` binding, or null.
     *
     * VALID means: a handle for which this same answer carries an `end`. Anything else — an invented
     * handle, a relation nobody is ending, a handle that exists but is only being updated — drops the
     * FIELD and warns. The operation survives because a new relation is a complete statement on its
     * own; refusing it would throw away a fact to punish an annotation.
     *
     * @param  array<int, string>  $ended
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private static function replacesHandle(mixed $value, array $ended, array &$warnings): ?string
    {
        $handle = self::handle($value, 'R');

        if ($handle === null && $value !== null) {
            $warnings[] = ['code' => self::WARN_REPLACES_UNBOUND, 'replaces' => is_string($value) ? $value : ''];

            return null;
        }

        if ($handle === null) {
            return null;
        }

        if (!in_array($handle, $ended, true)) {
            $warnings[] = ['code' => self::WARN_REPLACES_UNBOUND, 'replaces' => $handle];

            return null;
        }

        return $handle;
    }

    /**
     * `update` and `end`, which both name an EXISTING relation by its `R<n>` handle.
     *
     * @param  array<string, mixed>  $update
     * @param  array<int, array<string, mixed>>  $rejected
     * @return array<string, mixed>|null
     */
    private static function existingOp(string $op, array $update, GraphOpsContext $context, array &$rejected): ?array
    {
        $handle = self::text($update['relation'] ?? null, 16);
        $relation = $handle === null ? null : $context->relation($handle);

        if ($relation === null) {
            $rejected[] = ['code' => self::REJECT_UNKNOWN_HANDLE, 'scope' => 'graph_updates', 'relation' => $handle];

            return null;
        }

        $type = KnowledgeRelationType::tryFrom($relation['type']);
        [$properties, $offending] = $type === null
            ? [[], null]
            : self::properties($update['properties'] ?? null, $type);

        if ($offending !== null) {
            $rejected[] = ['code' => self::REJECT_PROPERTIES, 'relation' => $handle, 'property' => $offending];

            return null;
        }

        return [
            'op' => $op,
            'relation' => $handle,
            'description' => self::text($update['description'] ?? null, self::MAX_DESCRIPTION),
            'properties' => $properties,
            // Only `end` carries a closing date; on an `update` it is simply absent.
            'valid_to' => $op === 'end' ? self::date($update['valid_to'] ?? null) : null,
        ];
    }

    /**
     * The type the matrix should judge a handle by.
     *
     * An EXISTING entity answers with its stored type — the model's opinion about something the
     * workspace has already classified is worth strictly less than the classification. A NEW one
     * answers with the type declared for it in the same answer, which is legitimate because that is
     * the only type that thing has.
     *
     * @param  array<string, ?KnowledgeEntryType>  $newTypes
     */
    private static function handleType(string $handle, GraphOpsContext $context, array $newTypes): ?KnowledgeEntryType
    {
        return $context->entity($handle)['entry_type'] ?? ($newTypes[$handle] ?? null);
    }

    /**
     * The TITLE behind a handle — an existing entity's, or the one this answer declared for a new one.
     *
     * Presentation only, and only ever used on a refusal: a reviewer reading "N6 → N7" has been told
     * nothing they can act on.
     *
     * @param  array<string, ?string>  $newTitles
     */
    private static function handleTitle(string $handle, GraphOpsContext $context, array $newTitles): ?string
    {
        $title = $context->entity($handle)['title'] ?? ($newTitles[$handle] ?? null);

        return is_string($title) && $title !== '' ? $title : null;
    }

    // ---- field normalization -------------------------------------------------------

    /**
     * A properties map, against the verb's own allow-list.
     *
     * The bag is where a schema goes to die, so it is bounded four ways at once: how many keys, how
     * long a key, what a value may be, and — the one that matters — WHICH keys this verb declares.
     * Anything outside drops the OPERATION rather than the field, because a property is a qualifier on
     * a statement: keeping "Anna member_of Acme" while dropping `role: contractor` changes what the
     * statement says.
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private static function properties(mixed $raw, KnowledgeRelationType $type): array
    {
        if ($raw === null) {
            return [[], null];
        }

        if (!is_array($raw)) {
            return [[], ''];
        }

        if (count($raw) > self::MAX_PROPERTY_KEYS) {
            return [[], '__too_many'];
        }

        $allowed = $type->propertyKeys();
        $clean = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) || mb_strlen($key) > self::MAX_PROPERTY_KEY_CHARS || !in_array($key, $allowed, true)) {
                return [[], is_string($key) ? $key : ''];
            }

            // SCALARS ONLY. Nesting would turn this into a second, unvalidated document store hanging
            // off an edge — and a place for a directive to hide from a shallow scan.
            if (!is_scalar($value) && $value !== null) {
                return [[], $key];
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_PROPERTY_VALUE_CHARS) {
                return [[], $key];
            }

            $clean[$key] = is_string($value) ? self::text($value, self::MAX_PROPERTY_VALUE_CHARS) : $value;
        }

        return [$clean, null];
    }

    /**
     * An ISO date, or NULL.
     *
     * DROPPED, never clamped or reinterpreted — the `duration` pattern. "yesterday" is not a date this
     * system can store, and guessing what it meant would write a fact nobody asserted; a relation
     * without a start date is still a true statement, one with the wrong date is not.
     */
    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', trim($value)));

        return checkdate($month, $day, $year) ? trim($value) : null;
    }

    /** `E3`, `R7`, `N1` — anything else is not a handle. */
    private static function handle(mixed $value, string $prefix): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = strtoupper(trim($value));

        return preg_match('/^' . $prefix . '\d{1,3}$/', $clean) === 1 ? $clean : null;
    }

    private static function entryType(mixed $value): ?string
    {
        return is_string($value) ? KnowledgeEntryType::tryFrom($value)?->value : null;
    }

    /** @return array<int, string> */
    private static function aliases(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return EntryAliases::normalize(array_filter($value, 'is_string'));
    }

    /**
     * The model's own "I could not tell" list. Kept, because a stated ambiguity is a question a human
     * can answer in one click, while a silent one is a wrong fact waiting to be written.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function unresolved(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($raw, 0, 40) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mention = self::text($item['mention'] ?? null, 120);

            if ($mention === null) {
                continue;
            }

            $clean[] = ['mention' => $mention, 'note' => self::text($item['note'] ?? null, self::MAX_DESCRIPTION)];
        }

        return $clean;
    }

    /**
     * Ambiguities the resolution pass RAISED and the answer never settled.
     *
     * The model gets first refusal because it has the sentence; whatever it declines becomes a question
     * for the human, and it has to be visible or nobody ever answers it. Detected here rather than
     * trusted from the model's own `unresolved[]`, which it may simply have forgotten to write.
     *
     * @param  array<int, array<string, mixed>>  $graph
     * @param  array<int, array<string, mixed>>  $wiki
     * @param  array<int, array<string, mixed>>  $warnings
     */
    private static function reportUnansweredAmbiguity(GraphOpsContext $context, array $graph, array $wiki, array &$warnings): void
    {
        if ($context->ambiguous === []) {
            return;
        }

        $used = array_merge(
            array_column($wiki, 'entity'),
            array_column($graph, 'from'),
            array_column($graph, 'to'),
        );

        foreach ($context->ambiguous as $mention => $candidates) {
            if (array_intersect($candidates, $used) === []) {
                $warnings[] = [
                    'code' => self::WARN_AMBIGUOUS_UNRESOLVED,
                    'mention' => (string) $mention,
                    'candidates' => array_values($candidates),
                ];
            }
        }
    }

    /** One normalized string: strings only, control characters stripped, fence scrubbed, capped. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim(KnowledgeFence::sanitize(
            (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)
        ));

        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) : $clean;
    }
}
