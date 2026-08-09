<?php

namespace App\Modules\Knowledge\Enums;

/**
 * WHAT KIND OF THING an entry is about.
 *
 * It exists for exactly one job: to let {@see \App\Modules\Knowledge\Support\RelationVocabulary} say
 * that "a person WORKS ON a project" is a sentence and "a city WORKS ON a person" is not. Nothing else
 * in the module reads it, and that narrowness is the design — a taxonomy invented for its own sake
 * grows until nobody can decide which box a note belongs in.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THESE EIGHT
 *
 * The set is derived BACKWARDS from the fifteen relation types rather than from any general ontology:
 * a class earns its place only if some relation needs to tell it apart from another class. Anything
 * finer would be distinctions the verbs cannot use, and anything coarser would let the matrix through
 * sentences it should catch.
 *
 *   person        the only class that can `know` another, and the usual subject of `member_of`,
 *                 `works_on`, `created` and `participated_in`.
 *   organization  a company, team, institution — a thing people are MEMBERS OF, which is what
 *                 separates it from a person (who is not) and from a concept (which nobody joins).
 *   event         a thing that HAPPENED, in time. `occurred_during`, `participated_in` and `caused`
 *                 all need it, and none of them make sense about a document.
 *   place         a thing other things are `located_in`. The only class that is a valid target there,
 *                 which is precisely what stops "the refund policy is located in the CEO".
 *   product       a thing offered or shipped — a target of `uses` and `depends_on`.
 *   work          an AUTHORED artefact: an article, a spec, a film, a report. Kept apart from
 *                 `product` because they take different verbs: a person `created` a work, while a
 *                 system `uses` or `depends_on` a product. Collapsing them would license "the team
 *                 created the product line" as the same claim as "Anna wrote the spec", and the whole
 *                 value of a typed graph is that those read differently.
 *   concept       an idea, a policy, a category, a term. The target of `is_a`, which is what makes
 *                 taxonomy expressible at all.
 *   other         the escape hatch, and NOT a failure of the taxonomy. Without it, typing a base
 *                 would force a writer to file a note under a class it does not belong to — and a
 *                 wrong type is worse than no type, because the pair matrix then REFUSES real
 *                 relations with total confidence. `other` participates in nothing the matrix
 *                 constrains; it says "typed, but not one of these".
 *
 * NULL — the absence of a type — is a different statement from `other` and far more common: it is
 * every entry written before this column existed. The matrix treats null as UNKNOWN and only warns,
 * while `other` is a deliberate answer. Conflating them would have made a base's whole history
 * un-relatable overnight.
 */
enum KnowledgeEntryType: string
{
    case PERSON = 'person';
    case ORGANIZATION = 'organization';
    case EVENT = 'event';
    case PLACE = 'place';
    case PRODUCT = 'product';
    case WORK = 'work';
    case CONCEPT = 'concept';
    case OTHER = 'other';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The translated noun a UI labels the badge with. */
    public function label(): string
    {
        return __('knowledge.entry_types.' . $this->value);
    }

    /**
     * Whether the pair matrix may draw a conclusion about this type at all.
     *
     * `other` deliberately says no: it is "none of the above", so constraining relations by it would
     * be inventing a rule out of the writer's admission that no rule applies.
     */
    public function isMatchable(): bool
    {
        return $this !== self::OTHER;
    }
}
