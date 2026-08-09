<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;

/**
 * WHICH RELATIONS A GIVEN BASE MAY RECORD — the two gates between "the product knows this verb" and
 * "this base is willing to store this statement".
 *
 * ------------------------------------------------------------------------------------------------
 * 1. THE PER-BASE ALLOW-LIST
 *
 * A base may narrow the vocabulary to a subset. NULL means the whole vocabulary and `[]` means none,
 * and those are DELIBERATELY different: a base that never thought about relation types would
 * otherwise be silently stricter than one that chose to allow nothing, which is the opposite of what
 * either owner meant. Narrowing is worth having because a base about company policy has no use for
 * `knows` or `occurred_during`, and a picker offering fifteen verbs where three apply is a picker
 * people stop reading.
 *
 * ------------------------------------------------------------------------------------------------
 * 2. THE TYPE MATRIX — ADVISORY WHERE THE TYPE IS UNKNOWN
 *
 * "A person WORKS ON a project" is a sentence; "a city WORKS ON a person" is not, and catching the
 * second is most of the value of typing a graph at all.
 *
 * But the check only RUNS when both ends are typed. Every entry written before `entry_type` existed
 * carries null, and a matrix that refused on an unknown type would reject correct relations across a
 * whole base with total confidence — destroying real work to enforce a rule about data nobody had
 * been asked for. So an unknown end yields {@see RelationVerdict::UNKNOWN_TYPES}, which the review
 * report shows as a warning and the HTTP write accepts.
 *
 * THIS IS A DELIBERATE DEPARTURE from a strict-mode posture, and it is worth naming as one: elsewhere
 * this module fails closed (the directive guard, the amendment allow-list, the phrase matcher). The
 * difference is what a false refusal costs. There, a refusal costs one save that can be retried; here
 * it would cost a base its ability to record anything true about its own history, and the "safe"
 * direction would be the destructive one.
 *
 * `other` is treated as unknown for the same reason in reverse: it is the writer saying no class
 * applies, so constraining by it would be inventing a rule out of an admission that none exists.
 */
final class RelationVocabulary
{
    /**
     * The verbs this base allows, in the vocabulary's own order.
     *
     * @return array<int, KnowledgeRelationType>
     */
    public static function for(KnowledgeBase $base): array
    {
        $allowed = $base->relation_types;

        // NULL — never configured — means everything. See the class docblock for why that is not the
        // same as `[]`.
        if (!is_array($allowed)) {
            return KnowledgeRelationType::cases();
        }

        $ids = array_values(array_filter(
            array_map(static fn ($id): string => is_string($id) ? $id : '', $allowed),
            static fn (string $id): bool => in_array($id, KnowledgeRelationType::ids(), true),
        ));

        return array_values(array_filter(
            KnowledgeRelationType::cases(),
            static fn (KnowledgeRelationType $type): bool => in_array($type->value, $ids, true),
        ));
    }

    /** @return array<int, string> */
    public static function idsFor(KnowledgeBase $base): array
    {
        return array_map(
            static fn (KnowledgeRelationType $type): string => $type->value,
            self::for($base),
        );
    }

    public static function allows(KnowledgeBase $base, KnowledgeRelationType $type): bool
    {
        return in_array($type, self::for($base), true);
    }

    /**
     * Whether this verb may join these two entries — the matrix, with its advisory band.
     *
     * Takes the ENTRIES rather than their types so a caller cannot accidentally ask the question about
     * the wrong end: the argument order is the relation's own direction, and reading the types out
     * here is the one place that mapping is made.
     */
    public static function check(KnowledgeRelationType $type, KnowledgeEntry $from, KnowledgeEntry $to): RelationVerdict
    {
        return self::checkTypes($type, self::typeOf($from), self::typeOf($to));
    }

    /** The same question asked about bare types, for callers that have no models to hand. */
    public static function checkTypes(KnowledgeRelationType $type, ?KnowledgeEntryType $from, ?KnowledgeEntryType $to): RelationVerdict
    {
        if ($from === null || $to === null || !$from->isMatchable() || !$to->isMatchable()) {
            return RelationVerdict::UNKNOWN_TYPES;
        }

        return in_array($from, $type->fromTypes(), true) && in_array($to, $type->toTypes(), true)
            ? RelationVerdict::ALLOWED
            : RelationVerdict::REFUSED;
    }

    /** An entry's declared type, or null when it has none (which is most entries). */
    public static function typeOf(KnowledgeEntry $entry): ?KnowledgeEntryType
    {
        $type = $entry->entry_type;

        if ($type instanceof KnowledgeEntryType) {
            return $type;
        }

        return is_string($type) ? KnowledgeEntryType::tryFrom($type) : null;
    }

    /**
     * Reduce a properties map to the keys this verb declares, or report the first key it does not.
     *
     * Refusing an unknown key rather than dropping it is the metadata validator's stance, held for the
     * same reason: a fact that disappears on save is worse than one that was rejected, because the
     * writer believes it was stored.
     *
     * THE TWO UNIVERSAL KEYS ARE VALIDATED BY VALUE, not only by name. `sentiment` and `confidence`
     * exist to be FILTERED ON — "show me what went badly around this person" — and a column you filter
     * on is worth exactly as much as its value set is disciplined. One edge saying `negative`, another
     * `bad` and a third `zły` is three unrelated values and no answer. Every other key stays free text,
     * because a `role` or a `purpose` is prose somebody reads rather than a facet somebody groups by.
     *
     * @param  array<string, mixed>  $properties
     * @return array{0: array<string, mixed>, 1: ?string} the clean map, and the offending key if any
     */
    public static function checkProperties(KnowledgeRelationType $type, array $properties): array
    {
        $allowed = $type->propertyKeys();
        $clean = [];

        foreach ($properties as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return [[], is_string($key) ? $key : ''];
            }

            $values = match ($key) {
                'sentiment' => KnowledgeRelationType::SENTIMENTS,
                'confidence' => KnowledgeRelationType::CONFIDENCES,
                default => null,
            };

            if ($values !== null && !in_array($value, $values, true)) {
                // Reported as the offending KEY, like an unknown one, so it travels the existing
                // refusal path and the reviewer is told which field was wrong rather than being handed
                // an edge quietly missing its sentiment.
                return [[], $key];
            }

            // SCALARS ONLY. A property is one detail about a statement — a role, a share — and nesting
            // would turn this column into a second, unvalidated document store hanging off an edge.
            if ($value === null || is_scalar($value)) {
                $clean[$key] = is_string($value) ? mb_substr(trim($value), 0, 200) : $value;

                continue;
            }

            return [[], $key];
        }

        return [$clean, null];
    }
}
