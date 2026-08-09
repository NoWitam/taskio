<?php

namespace App\Modules\Knowledge\Enums;

/**
 * THE VERB of a typed relation — the twenty statements this product is willing to record between two
 * entries.
 *
 * A CLOSED vocabulary, and that is the whole value. Free-text predicates read beautifully and are
 * useless: "works on", "working on", "pracuje nad" and "assigned to" become four unrelated edges, and
 * nothing can ever query them together. Twenty verbs are few enough that a person can hold them in
 * mind while reading a graph, and a base that needs a twenty-first can say so.
 *
 * ------------------------------------------------------------------------------------------------
 * STATES AND EPISODES
 *
 * The original fifteen describe what something IS: a member, an owner, a part. Real material is mostly
 * about what happened — a visit, a contest, an apology — and those have an author, a direction and an
 * end date. `visited`, `organized`, `won` and `interacted_with` exist for that half, and `related_to`
 * catches what none of them fit rather than letting the fact fall on the floor.
 *
 * ------------------------------------------------------------------------------------------------
 * SYMMETRY
 *
 * `knows` and `opposes` are the only symmetric verbs: if A knows B then B knows A, and there is no
 * meaningful direction to the claim. They are STORED ONCE, in a canonical direction (the smaller id
 * first), and RENDERED both ways. Storing both directions would double every row and let the two
 * halves disagree — one ended, one not — with no way to say which is right. Storing one direction
 * without canonicalising it would make "does this already exist" depend on the order the writer
 * happened to pick.
 *
 * Every other verb is genuinely directed: "Anna created the report" and "the report created Anna" are
 * not the same sentence, and a graph that cannot tell them apart is decoration.
 *
 * ------------------------------------------------------------------------------------------------
 * PROPERTIES
 *
 * Each verb declares the keys it accepts, and most accept none. A property is for the one detail that
 * would otherwise force a second relation type ("member_of with role=CTO" rather than a `cto_of`
 * verb); it is NOT a general-purpose bag, because a bag is where a schema goes to die. Unknown keys
 * are REFUSED rather than dropped — the same stance the metadata validator takes, and for the same
 * reason: a fact that silently vanishes on save is worse than one that was rejected, because the
 * writer believes it was stored.
 *
 * ------------------------------------------------------------------------------------------------
 * THE TYPE MATRIX IS ADVISORY, NOT A GATE — BUT IT IS NO LONGER DORMANT
 *
 * {@see fromTypes()} / {@see toTypes()} say which entry types each end may have. They are enforced
 * ONLY when BOTH ends have a known type — see {@see \App\Modules\Knowledge\Support\RelationVocabulary}
 * for why an unknown type warns instead of refusing. Every entry written before `entry_type` existed
 * is untyped, and a matrix that refused on a guess would reject correct relations across a whole base
 * with total confidence.
 *
 * That escape hatch used to swallow the matrix whole: the composer never set a type on anything it
 * wrote, so every composed relation had an untyped end and NOTHING was ever checked. Now that the
 * composer types its entries, this matrix decides real writes — which is why the composer is also
 * SHOWN it (see the agent's instructions). Tightening a row here without updating that list refuses
 * sentences the model was never told were wrong.
 */
enum KnowledgeRelationType: string
{
    // --- people and organisations ---------------------------------------------------
    case MEMBER_OF = 'member_of';
    case WORKS_ON = 'works_on';
    case KNOWS = 'knows';
    case CREATED = 'created';
    case OWNS = 'owns';

    // --- place and time ---------------------------------------------------------------
    case LOCATED_IN = 'located_in';
    case PARTICIPATED_IN = 'participated_in';
    case OCCURRED_DURING = 'occurred_during';

    // --- structure --------------------------------------------------------------------
    case PART_OF = 'part_of';
    case IS_A = 'is_a';

    // --- dependency and consequence ---------------------------------------------------
    case USES = 'uses';
    case DEPENDS_ON = 'depends_on';
    case PRECEDES = 'precedes';
    case CAUSED = 'caused';
    case OPPOSES = 'opposes';

    // --- episodes: what a subject DID, as opposed to what it IS -----------------------
    //
    // The vocabulary above describes standing states, which is what a knowledge base is mostly for. It
    // had no way at all to say that something HAPPENED to a subject and then stopped. The only
    // available verb for "spent three days in Paris" was `located_in`, which means "is situated in" —
    // with dates attached it produced a graph asserting that a traveller is permanently in every city
    // they ever visited.
    case VISITED = 'visited';
    case ORGANIZED = 'organized';
    case WON = 'won';

    /**
     * ONE SUBJECT ACTED ON ANOTHER — and the only directed verb between two people.
     *
     * The gap this fills is the sharpest one in the vocabulary: between two persons there were exactly
     * two verbs, `knows` and `opposes`, and BOTH are symmetric. An apology, a piece of criticism, a
     * public thanks — every act with an author and a recipient — could not be recorded with its
     * direction, so "Łukasz apologised to her" and "she apologised to Łukasz" were the same edge.
     *
     * `act` names what was done and `sentiment` says how it landed, which is what makes a graph of
     * these filterable rather than a pile of undirected acquaintance.
     */
    case INTERACTED_WITH = 'interacted_with';

    /**
     * THE FALLBACK, and deliberately the weakest verb here.
     *
     * The rule until now was that a relation whose meaning is not in the vocabulary is not recorded at
     * all — which reads as principled and in practice means a stated fact is destroyed because no verb
     * fit. A weak edge carrying the sentence in its `description` is worth more than silence: it is
     * findable, it is reviewable, and somebody can retype it later. It is LAST RESORT, and the prompt
     * says so, because a vocabulary whose escape hatch is comfortable stops being a vocabulary.
     */
    case RELATED_TO = 'related_to';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The verb as a UI renders it on an edge — "is a member of". */
    public function label(): string
    {
        return __('knowledge.relation_types.' . $this->value);
    }

    /** The same statement read backwards, for the target's side of the panel — "has member". */
    public function inverseLabel(): string
    {
        return __('knowledge.relation_types_inverse.' . $this->value);
    }

    /**
     * Whether the claim is the same in both directions.
     *
     * A symmetric relation is stored ONCE with the smaller entry id first (see the class docblock) and
     * drawn undirected.
     */
    public function isSymmetric(): bool
    {
        return match ($this) {
            // `related_to` joins the two originals: "these two have something to do with each other"
            // is the same claim from either end. `interacted_with` is deliberately NOT here — see its
            // case docblock; direction is the whole reason it exists.
            self::KNOWS, self::OPPOSES, self::RELATED_TO => true,
            default => false,
        };
    }

    /**
     * Keys EVERY verb accepts.
     *
     * The one deliberate loosening of the closed-bag rule above, and it is narrow on purpose: two keys,
     * both from a fixed value list, both about the EDGE ITSELF rather than about its subject matter.
     *
     * They exist because a graph you cannot filter by tone or trust is a graph you can only read one
     * edge at a time. "Show me what went wrong around this person" is the question a reader actually
     * has, and before this there was no verb-independent place to answer it from — `opposes` carried a
     * free-text `reason` and every other verb carried nothing.
     *
     * NOT `state`. `active/ended/retracted` answers "do we still assert this", not "how did it go":
     * folding sentiment into it would break `scopeCurrent()`, the historical filter and the audit trail
     * in one move.
     *
     * @var array<int, string>
     */
    private const UNIVERSAL_PROPERTIES = ['sentiment', 'confidence'];

    /** Allowed values, per universal key. Validated by {@see RelationVocabulary::checkProperties()}. */
    public const SENTIMENTS = ['positive', 'negative', 'neutral', 'mixed'];

    public const CONFIDENCES = ['low', 'medium', 'high'];

    /**
     * The property keys this verb accepts. Anything else is refused, not dropped.
     *
     * @return array<int, string>
     */
    public function propertyKeys(): array
    {
        return array_merge(self::UNIVERSAL_PROPERTIES, $this->ownPropertyKeys());
    }

    /** @return array<int, string> */
    private function ownPropertyKeys(): array
    {
        return match ($this) {
            // "member_of an organisation, AS the CTO" — the alternative was a verb per job title.
            self::MEMBER_OF, self::WORKS_ON, self::CREATED, self::PARTICIPATED_IN => ['role'],
            // How the two know each other; the thing that makes the edge worth having at all.
            self::KNOWS => ['how'],
            // Part ownership is common enough in the cases this verb is for that "owns" alone would be
            // misleading.
            self::OWNS => ['share'],
            self::USES => ['purpose'],
            self::DEPENDS_ON => ['kind'],
            self::OPPOSES => ['reason'],
            // Why the subject went, and in what capacity it ran or won the thing.
            self::VISITED => ['purpose'],
            self::ORGANIZED => ['role'],
            self::WON => ['rank'],
            // WHAT was done — apologised, criticised, supported, thanked. Kept to one short word by
            // the prompt rather than by this list, because the set of human acts is not closed and
            // pretending otherwise would push every unlisted act back into silence.
            self::INTERACTED_WITH => ['act'],
            self::RELATED_TO => ['note'],
            // The rest are complete statements on their own. `located_in`, `part_of`, `is_a`,
            // `occurred_during`, `precedes` and `caused` say everything they mean in the verb.
            default => [],
        };
    }

    /**
     * Entry types the SOURCE end may have.
     *
     * @return array<int, KnowledgeEntryType>
     */
    public function fromTypes(): array
    {
        return match ($this) {
            self::MEMBER_OF => [KnowledgeEntryType::PERSON, KnowledgeEntryType::ORGANIZATION],
            self::WORKS_ON => [KnowledgeEntryType::PERSON, KnowledgeEntryType::ORGANIZATION],
            self::KNOWS => [KnowledgeEntryType::PERSON],
            self::CREATED => [KnowledgeEntryType::PERSON, KnowledgeEntryType::ORGANIZATION],
            self::OWNS => [KnowledgeEntryType::PERSON, KnowledgeEntryType::ORGANIZATION],
            // NO PERSON. A person is not situated anywhere in the sense this verb means — they were
            // there for a while, which is `visited`. Leaving both open would give one base two
            // conventions for the same fact and make "where has she been" unanswerable.
            self::LOCATED_IN => [
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PLACE,
                KnowledgeEntryType::PRODUCT,
            ],
            self::PARTICIPATED_IN => [KnowledgeEntryType::PERSON, KnowledgeEntryType::ORGANIZATION],
            self::VISITED, self::ORGANIZED, self::WON, self::INTERACTED_WITH => [
                KnowledgeEntryType::PERSON,
                KnowledgeEntryType::ORGANIZATION,
            ],
            // The fallback constrains nothing — constraining it would defeat the point of having it.
            self::RELATED_TO => KnowledgeEntryType::cases(),
            self::OCCURRED_DURING => [KnowledgeEntryType::EVENT],
            self::PART_OF => [
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PLACE,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            // Taxonomy: anything at all may be an instance of a concept.
            self::IS_A => KnowledgeEntryType::cases(),
            self::USES => [
                KnowledgeEntryType::PERSON,
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
            ],
            self::DEPENDS_ON => [
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            self::PRECEDES => [
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            self::CAUSED => [
                KnowledgeEntryType::PERSON,
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::CONCEPT,
            ],
            self::OPPOSES => [
                KnowledgeEntryType::PERSON,
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::CONCEPT,
            ],
        };
    }

    /**
     * Entry types the TARGET end may have.
     *
     * @return array<int, KnowledgeEntryType>
     */
    public function toTypes(): array
    {
        return match ($this) {
            self::MEMBER_OF => [KnowledgeEntryType::ORGANIZATION],
            self::WORKS_ON => [
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            // Symmetric: both ends are the same kind of thing, by definition.
            self::KNOWS => [KnowledgeEntryType::PERSON],
            self::CREATED => [
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
                KnowledgeEntryType::ORGANIZATION,
            ],
            self::OWNS => [
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::PLACE,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
            ],
            // The one verb whose target is a single type — which is what stops "the refund policy is
            // located in the CEO" from being a sentence this base will store.
            self::LOCATED_IN => [KnowledgeEntryType::PLACE],
            self::PARTICIPATED_IN => [KnowledgeEntryType::EVENT],
            self::OCCURRED_DURING => [KnowledgeEntryType::EVENT],
            self::PART_OF => [
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PLACE,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            // ...and always a concept on the other end. `is_a` IS the taxonomy verb.
            self::IS_A => [KnowledgeEntryType::CONCEPT],
            self::USES => [
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            self::DEPENDS_ON => [
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            self::PRECEDES => [
                KnowledgeEntryType::EVENT,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
                KnowledgeEntryType::CONCEPT,
            ],
            self::CAUSED => [KnowledgeEntryType::EVENT, KnowledgeEntryType::CONCEPT],
            self::OPPOSES => [
                KnowledgeEntryType::PERSON,
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::CONCEPT,
            ],
            // A stay is somewhere; an appearance is at something named.
            self::VISITED => [KnowledgeEntryType::PLACE, KnowledgeEntryType::EVENT],
            self::ORGANIZED => [KnowledgeEntryType::EVENT],
            self::WON => [KnowledgeEntryType::EVENT, KnowledgeEntryType::WORK],
            // ACTED ON A THING, TOO — not only on somebody.
            //
            // The gap this closes came off real material: "Zero spowodował awarię Nexusa" had nowhere
            // to go. `caused` deliberately targets `event|concept` only, because it means "brought
            // about this outcome"; widening it to concrete objects would make the base assert "Zero
            // caused Nexus-7", which is not a sentence anybody wrote. And the subject doctrine forbids
            // inventing an "awaria" event entry to sit in the middle. So the fact was simply lost.
            //
            // `interacted_with` already carries `act` and `sentiment` and already means "this subject
            // did something to that one". Letting the far end be a product or a work keeps the verb's
            // meaning intact and gives the fact somewhere true to live: attacked, sabotaged, repaired,
            // reviewed.
            self::INTERACTED_WITH => [
                KnowledgeEntryType::PERSON,
                KnowledgeEntryType::ORGANIZATION,
                KnowledgeEntryType::PRODUCT,
                KnowledgeEntryType::WORK,
            ],
            self::RELATED_TO => KnowledgeEntryType::cases(),
        };
    }

    /**
     * The whole vocabulary as the API publishes it — one payload a client renders every picker,
     * badge and validation hint from, so the UI can never disagree with the server about what a verb
     * accepts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(static fn (self $type): array => [
            'id' => $type->value,
            'label' => $type->label(),
            'inverse_label' => $type->inverseLabel(),
            'symmetric' => $type->isSymmetric(),
            'property_keys' => $type->propertyKeys(),
            'from_types' => array_map(static fn (KnowledgeEntryType $entry): string => $entry->value, $type->fromTypes()),
            'to_types' => array_map(static fn (KnowledgeEntryType $entry): string => $entry->value, $type->toTypes()),
        ], self::cases());
    }
}
