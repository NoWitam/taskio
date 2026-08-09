<?php

namespace Tests\Unit\Knowledge;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Support\GraphOpsContext;
use App\Modules\Knowledge\Support\KnowledgeGraphOps;
use App\Modules\Knowledge\Support\TemplateDirectiveGuard;
use Tests\TestCase;

/**
 * G4 — THE LAUNDERING, tested against HOSTILE OUTPUT.
 *
 * This class is the security boundary for a model-written object that proposes changes to a knowledge
 * base's graph, so the tests are written the way an attacker would probe it rather than the way a
 * cooperative model would use it. Every case asserts the same two things: nothing is written that
 * should not be, and nothing throws — a 500 on a session a user is watching is itself a failure, and
 * an exception here would hide the report that explains what happened.
 *
 * NO DATABASE, which is the payoff of {@see GraphOpsContext}: the facts are gathered once by a service
 * and the decisions are then total, replayable, and drivable from a hand-written fixture — so a
 * hostile answer can be probed dozens of ways without a single row existing.
 */
class KnowledgeGraphOpsTest extends TestCase
{
    private function context(array $overrides = []): GraphOpsContext
    {
        return new GraphOpsContext(
            entities: $overrides['entities'] ?? [
                'E1' => [
                    'id' => 'id-anna',
                    'slug' => 'anna',
                    'title' => 'Anna',
                    'entry_type' => KnowledgeEntryType::PERSON,
                    'truncated' => false,
                    'revision' => 'rev-anna-read',
                    'content' => 'Anna pracuje w [[acme]] i zna [[bob]].',
                ],
                'E2' => [
                    'id' => 'id-acme',
                    'slug' => 'acme',
                    'title' => 'Acme',
                    'entry_type' => KnowledgeEntryType::ORGANIZATION,
                    'truncated' => true,
                    'revision' => 'rev-acme-read',
                    'content' => 'Firma Acme. [[cennik]]',
                ],
            ],
            relations: $overrides['relations'] ?? [
                'R1' => ['id' => 'rel-1', 'type' => 'member_of', 'from_entry_id' => 'id-anna', 'to_entry_id' => 'id-acme'],
            ],
            allowedTypes: $overrides['allowedTypes'] ?? KnowledgeRelationType::cases(),
            existing: $overrides['existing'] ?? [],
            ambiguous: $overrides['ambiguous'] ?? [],
        );
    }

    private function launder(array $raw, array $contextOverrides = []): KnowledgeGraphOps
    {
        return KnowledgeGraphOps::fromArray($raw, $this->context($contextOverrides), new TemplateDirectiveGuard);
    }

    /** @return array<int, string> */
    private function codes(array $items): array
    {
        return array_column($items, 'code');
    }

    // ---- handles ---------------------------------------------------------------------

    public function test_an_invented_handle_drops_the_operation_and_is_reported(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E9', 'type' => 'knows'],
        ]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_UNKNOWN_HANDLE, $this->codes($ops->rejected));
    }

    public function test_an_invented_relation_handle_drops_the_operation(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'end', 'relation' => 'R99', 'valid_to' => '2026-08-04'],
        ]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_UNKNOWN_HANDLE, $this->codes($ops->rejected));
    }

    /** A new entity is usable IMMEDIATELY, in the same answer that declared it. */
    public function test_a_declared_entity_may_be_related_in_the_same_answer(): void
    {
        $ops = $this->launder([
            'entities' => [['ref' => 'N1', 'title' => 'Bob', 'type' => 'person']],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'knows']],
        ]);

        $this->assertCount(1, $ops->entities);
        $this->assertCount(1, $ops->graphUpdates);
        $this->assertSame('N1', $ops->graphUpdates[0]['to']);
    }

    // ---- the vocabulary --------------------------------------------------------------

    /**
     * A verb outside the closed list is DROPPED AND REPORTED — never approximated.
     *
     * Filing "mentored" as `knows` would make the base claim something nobody said. Reporting the drop
     * is what turns a silent gap in the vocabulary into the evidence for widening it.
     */
    public function test_a_type_outside_the_vocabulary_is_dropped_with_a_report(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'mentored'],
        ]]);

        $this->assertSame([], $ops->graphUpdates);

        $rejection = $ops->rejected[0];
        $this->assertSame(KnowledgeGraphOps::REJECT_UNKNOWN_TYPE, $rejection['code']);
        $this->assertSame('mentored', $rejection['type'], 'the report names the missing verb, which is the point of it');
    }

    public function test_a_type_the_base_does_not_allow_is_dropped(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows']]],
            ['allowedTypes' => [KnowledgeRelationType::MEMBER_OF]],
        );

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_TYPE_NOT_ALLOWED, $this->codes($ops->rejected));
    }

    // ---- the forbidden verbs -----------------------------------------------------------

    /**
     * THE OWNER'S DECISION, enforced here: a model has no power to delete or retract.
     *
     * It is dropped even when perfectly well-formed, because the danger is not malformed input — it is
     * a plausible-looking removal that a reviewer cannot notice, since a reviewer sees the operations
     * that ARE there.
     */
    public function test_delete_and_retract_are_always_dropped(): void
    {
        foreach (['delete', 'retract', 'remove', 'destroy'] as $op) {
            $ops = $this->launder(['graph_updates' => [
                ['op' => $op, 'relation' => 'R1'],
            ]]);

            $this->assertSame([], $ops->graphUpdates, $op);
            $this->assertContains(KnowledgeGraphOps::REJECT_FORBIDDEN_OP, $this->codes($ops->rejected), $op);
        }
    }

    public function test_a_self_relation_is_dropped(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E1', 'type' => 'knows'],
        ]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_SELF_LOOP, $this->codes($ops->rejected));
    }

    // ---- properties -----------------------------------------------------------------

    public function test_a_nested_property_drops_the_operation(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'properties' => ['role' => ['a' => 'b']]],
        ]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_PROPERTIES, $this->codes($ops->rejected));
    }

    public function test_too_many_properties_drops_the_operation(): void
    {
        $ops = $this->launder(['graph_updates' => [
            [
                'op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of',
                'properties' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5],
            ],
        ]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_PROPERTIES, $this->codes($ops->rejected));
    }

    public function test_a_property_key_the_type_does_not_declare_drops_the_operation(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'properties' => ['salary' => 1000]],
        ]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertSame('salary', $ops->rejected[0]['property']);
    }

    // ---- dates ------------------------------------------------------------------------

    /**
     * A malformed date drops the FIELD, not the operation — and is never guessed at.
     *
     * "yesterday" is not a date this system can store. A relation with no start date is still a true
     * statement; one with an invented date is not.
     */
    public function test_a_non_iso_date_is_dropped_without_losing_the_relation(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => 'wczoraj'],
        ]]);

        $this->assertCount(1, $ops->graphUpdates);
        $this->assertNull($ops->graphUpdates[0]['valid_from']);
    }

    public function test_an_impossible_date_is_dropped(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => '2026-02-31'],
        ]]);

        $this->assertNull($ops->graphUpdates[0]['valid_from']);
    }

    public function test_a_valid_iso_date_survives(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => '2026-08-04'],
        ]]);

        $this->assertSame('2026-08-04', $ops->graphUpdates[0]['valid_from']);
    }

    // ---- template syntax ----------------------------------------------------------------

    /**
     * FAIL-CLOSED, over the WHOLE object. An entry is data other features inject into prompts, so
     * smuggled directives are refused on every path — and checking the serialized whole means one
     * cannot hide in a nested property this method does not otherwise read.
     */
    public function test_template_syntax_anywhere_discards_the_entire_answer(): void
    {
        foreach ([
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows', 'description' => 'Zna {{ secret.token }}']]],
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows', 'properties' => ['how' => '@[variable]("x")']]]],
            ['entities' => [['ref' => 'N1', 'title' => 'Bob {{ x }}']]],
        ] as $index => $payload) {
            $ops = $this->launder($payload);

            $this->assertTrue($ops->isEmpty(), "payload {$index}");
            $this->assertSame([KnowledgeGraphOps::REJECT_DIRECTIVE], $this->codes($ops->rejected), "payload {$index}");
        }
    }

    // ---- the matrix -----------------------------------------------------------------------

    public function test_a_typed_pair_outside_the_matrix_is_dropped(): void
    {
        // A place cannot work on a person — both ends are typed, so the matrix is authoritative.
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'located_in']]],
            ['entities' => [
                'E1' => ['id' => 'a', 'slug' => 'a', 'title' => 'A', 'entry_type' => KnowledgeEntryType::PLACE, 'truncated' => false, 'content' => ''],
                'E2' => ['id' => 'b', 'slug' => 'b', 'title' => 'B', 'entry_type' => KnowledgeEntryType::PERSON, 'truncated' => false, 'content' => ''],
            ]],
        );

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_PAIR, $this->codes($ops->rejected));
    }

    /**
     * A REFUSAL NAMES THE SUBJECTS — "pair_refused N6 → N7" is not something anybody can act on.
     *
     * The same rule as `skipped[].missing`, for the same reason: a handle is an internal address, and
     * the person reading the review screen has never seen it.
     */
    public function test_a_refused_pair_is_reported_with_names_and_types(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'located_in']]],
            ['entities' => [
                'E1' => ['id' => 'a', 'slug' => 'shinjuku', 'title' => 'Shinjuku', 'entry_type' => KnowledgeEntryType::PLACE, 'truncated' => false, 'content' => ''],
                'E2' => ['id' => 'b', 'slug' => 'agent-k', 'title' => 'Agent K', 'entry_type' => KnowledgeEntryType::PERSON, 'truncated' => false, 'content' => ''],
            ]],
        );

        $rejected = $ops->rejected[0];

        $this->assertSame('Shinjuku', $rejected['from_title']);
        $this->assertSame('Agent K', $rejected['to_title']);
        $this->assertSame('place', $rejected['from_entry_type']);
        $this->assertSame('person', $rejected['to_entry_type']);
        // Reversing does not rescue this one — a person is not a place either — and the report says so
        // rather than implying a fix that would not work.
        $this->assertFalse($rejected['reversed_would_be_valid']);
    }

    /**
     * WHEN THE ENDS ARE MERELY BACKWARDS the report says so — and the server still does not swap them.
     *
     * Auto-reversing was considered and declined: the review card would then show the SERVER'S
     * sentence, correct-looking and indistinguishable from the model's, and a reviewer approving it
     * could not tell which of the two they were agreeing with. The hint carries the whole insight
     * without the base gaining a statement nobody wrote.
     */
    public function test_a_backwards_pair_is_reported_as_reversible(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of']]],
            ['entities' => [
                'E1' => ['id' => 'a', 'slug' => 'netwatch', 'title' => 'NetWatch', 'entry_type' => KnowledgeEntryType::ORGANIZATION, 'truncated' => false, 'content' => ''],
                'E2' => ['id' => 'b', 'slug' => 'agent-k', 'title' => 'Agent K', 'entry_type' => KnowledgeEntryType::PERSON, 'truncated' => false, 'content' => ''],
            ]],
        );

        $this->assertSame([], $ops->graphUpdates, 'nothing was written, in either direction');
        $this->assertTrue($ops->rejected[0]['reversed_would_be_valid']);
        $this->assertSame('NetWatch', $ops->rejected[0]['from_title']);
    }

    /**
     * A PERSON MAY ACT ON A THING — the fact that had nowhere to live.
     *
     * "Zero wrecked Nexus-7" was refused everywhere: `caused` targets outcomes (`event|concept`), and
     * the subject doctrine forbids inventing an "awaria" event entry to sit in the middle. Widening
     * `interacted_with` rather than `caused` leaves both verbs meaning what they say.
     */
    public function test_a_person_may_interact_with_a_product(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [[
                'op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'interacted_with',
                'description' => 'Spowodowal awarie.', 'properties' => ['act' => 'sabotaged', 'sentiment' => 'negative'],
            ]]],
            ['entities' => [
                'E1' => ['id' => 'a', 'slug' => 'zero', 'title' => 'Zero', 'entry_type' => KnowledgeEntryType::PERSON, 'truncated' => false, 'content' => ''],
                'E2' => ['id' => 'b', 'slug' => 'nexus-7', 'title' => 'Nexus-7', 'entry_type' => KnowledgeEntryType::PRODUCT, 'truncated' => false, 'content' => ''],
            ]],
        );

        $this->assertCount(1, $ops->graphUpdates);
        $this->assertSame([], $ops->rejected);
        $this->assertSame('sabotaged', $ops->graphUpdates[0]['properties']['act']);
    }

    /** ...while `caused` still refuses a concrete object, because "X caused Nexus-7" is not a sentence. */
    public function test_caused_still_refuses_an_object_as_its_outcome(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'caused']]],
            ['entities' => [
                'E1' => ['id' => 'a', 'slug' => 'zero', 'title' => 'Zero', 'entry_type' => KnowledgeEntryType::PERSON, 'truncated' => false, 'content' => ''],
                'E2' => ['id' => 'b', 'slug' => 'nexus-7', 'title' => 'Nexus-7', 'entry_type' => KnowledgeEntryType::PRODUCT, 'truncated' => false, 'content' => ''],
            ]],
        );

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_PAIR, $this->codes($ops->rejected));
    }

    /** An UNKNOWN type passes with a warning — every entry written before types existed is null. */
    public function test_an_untyped_end_passes_with_a_warning(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'located_in']]],
            ['entities' => [
                'E1' => ['id' => 'a', 'slug' => 'a', 'title' => 'A', 'entry_type' => null, 'truncated' => false, 'content' => ''],
                'E2' => ['id' => 'b', 'slug' => 'b', 'title' => 'B', 'entry_type' => KnowledgeEntryType::PERSON, 'truncated' => false, 'content' => ''],
            ]],
        );

        $this->assertCount(1, $ops->graphUpdates, 'refusing on a guess would reject correct relations wholesale');
        $this->assertContains(KnowledgeGraphOps::WARN_UNTYPED_PAIR, $this->codes($ops->warnings));
    }

    // ---- duplicates -------------------------------------------------------------------------

    public function test_a_duplicate_relation_is_dropped_with_the_existing_id(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of']]],
            ['existing' => [[
                'id' => 'rel-existing',
                'from' => 'id-anna',
                'to' => 'id-acme',
                'type' => 'member_of',
                'valid_from' => null,
            ]]],
        );

        $this->assertSame([], $ops->graphUpdates);
        $this->assertSame('rel-existing', $ops->rejected[0]['existing_relation_id']);
    }

    /** A different start date is a different fact, not a duplicate. */
    public function test_the_same_pair_on_another_date_is_not_a_duplicate(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => '2026-09-01']]],
            ['existing' => [[
                'id' => 'rel-existing',
                'from' => 'id-anna',
                'to' => 'id-acme',
                'type' => 'member_of',
                'valid_from' => '2026-08-01',
            ]]],
        );

        $this->assertCount(1, $ops->graphUpdates);
    }

    // ---- dates ----------------------------------------------------------------------------------

    /**
     * A `create` that ends before it begins is REFUSED HERE, not at the write.
     *
     * `date()` only ever answered whether each date was well-formed, and both of these are — so a
     * reversed PAIR passed laundering, reached the review screen as an operation to approve, and was
     * refused in `accept` after the reviewer had already chosen it. The value of this whole stage is
     * that a refusal is news the reviewer gets BEFORE deciding, so the pair rule belongs where every
     * other rule already is.
     */
    public function test_a_create_whose_dates_run_backwards_is_refused_with_a_reason(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => '2026-07-01', 'valid_to' => '2024-01-01'],
        ]]);

        $this->assertSame([], $ops->graphUpdates, 'it never reaches the review screen');
        $this->assertSame([KnowledgeGraphOps::REJECT_DATES], $this->codes($ops->rejected));
        $this->assertSame('2026-07-01', $ops->rejected[0]['valid_from']);
        $this->assertSame('2024-01-01', $ops->rejected[0]['valid_to']);
    }

    /** The same two dates the right way round are none of this rule's business. */
    public function test_a_create_whose_dates_run_forwards_is_kept(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => '2024-01-01', 'valid_to' => '2026-07-01'],
        ]]);

        $this->assertCount(1, $ops->graphUpdates);
        $this->assertSame([], $ops->rejected);
    }

    /**
     * AN `end` IS EXEMPT, and it has to be — the write path exempts it too.
     *
     * `valid_to` on an `end` closes a relation whose `valid_from` this answer never saw. A date before
     * that start means the START was wrong rather than the ending, and refusing would leave a statement
     * nobody can retire. Diverging from the service would be its own defect: the preview would refuse
     * something the write path accepts, and the reviewer would be told "no" about a correction the
     * system would in fact have made.
     */
    public function test_an_end_may_close_a_relation_before_its_recorded_start(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'end', 'relation' => 'R1', 'valid_to' => '1999-01-01'],
        ]]);

        $this->assertCount(1, $ops->graphUpdates);
        $this->assertSame('1999-01-01', $ops->graphUpdates[0]['valid_to']);
        $this->assertSame([], $ops->rejected);
    }

    // ---- replacement bindings -------------------------------------------------------------------

    /** `replaces` naming an `end` in the SAME answer binds the two. */
    public function test_replaces_binds_a_create_to_an_end_in_the_same_answer(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01'],
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'replaces' => 'R1'],
        ]]);

        $this->assertCount(2, $ops->graphUpdates);
        $this->assertSame('R1', $ops->graphUpdates[1]['replaces']);
        $this->assertSame([], $ops->warnings);
    }

    /**
     * ORDER DOES NOT MATTER: the binding is a property of the SET.
     *
     * A model writes in whatever order it thinks, and resolving `replaces` as we walked the list would
     * accept or drop the same pair depending on how it happened to sequence its answer — a rule nobody
     * could reason about from the outside.
     */
    public function test_replaces_binds_even_when_the_end_comes_second(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'replaces' => 'R1'],
            ['op' => 'end', 'relation' => 'R1'],
        ]]);

        $this->assertSame('R1', $ops->graphUpdates[0]['replaces']);
        $this->assertSame([], $ops->warnings);
    }

    /**
     * An UNBOUND `replaces` loses the FIELD, not the operation.
     *
     * A new relation is a complete statement on its own; refusing it because its footnote was wrong
     * would throw away a fact to punish an annotation. The reviewer is still told, because the model
     * believed it was recording a replacement and what lands is an unrelated new relation.
     */
    public function test_an_unbound_replaces_drops_the_field_and_keeps_the_relation(): void
    {
        foreach ([
            'R9',       // a handle nothing in this answer ends
            'R1',       // exists, but this answer only UPDATES it
            'nonsense', // not a handle at all
        ] as $replaces) {
            $ops = $this->launder(['graph_updates' => [
                ['op' => 'update', 'relation' => 'R1', 'description' => 'Poprawka.'],
                ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'replaces' => $replaces],
            ]]);

            $create = collect($ops->graphUpdates)->firstWhere('op', 'create');

            $this->assertNotNull($create, $replaces);
            $this->assertNull($create['replaces'], $replaces);
            $this->assertContains(KnowledgeGraphOps::WARN_REPLACES_UNBOUND, $this->codes($ops->warnings), $replaces);
        }
    }

    /**
     * `replaces` ON AN `end` IS NOT A THING, and must not become one by accident.
     *
     * The field belongs to `create` — "the relation I am writing supersedes that one". A model that
     * puts it on the ending instead is describing the same intent backwards, and the shape of the
     * answer must not quietly accept it: `graphOpPairs()` reads `replaces` off EVERY operation, so a
     * surviving field here would pair an `end` with itself and hand the reviewer one checkbox that
     * claims to commit two operations when only one exists.
     */
    public function test_an_end_carrying_replaces_does_not_grow_the_field(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01', 'replaces' => 'R1'],
        ]]);

        $this->assertCount(1, $ops->graphUpdates);
        $this->assertArrayNotHasKey('replaces', $ops->graphUpdates[0], 'an ending replaces nothing');
    }

    /**
     * A `replaces` BOUND TO AN `end` THAT WAS ITSELF REFUSED IS CUT.
     *
     * The pre-pass that collects "which relations this answer ends" runs BEFORE any operation is
     * judged, so it lists a handle whose `end` is later dropped — here by a properties bag the verb
     * does not declare. The binding would then outlive its partner, and applying the set wrote the new
     * relation while leaving the old one ACTIVE: the base asserting two contradictory facts at once,
     * with nothing anywhere marking the contradiction.
     *
     * INVERTED from the G8 pin, which recorded that behaviour rather than endorsing it. A second pass
     * now cuts every `replaces` whose `end` did not survive. The FIELD goes, not the operation — a new
     * relation is a complete statement on its own, and dropping a fact because its footnote lost its
     * referent throws away more than it protects — and the warning is what keeps the loss from being
     * silent.
     */
    public function test_a_replaces_is_cut_when_its_end_was_refused(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'end', 'relation' => 'R1', 'valid_to' => '2026-07-01', 'properties' => ['nie_ma_takiego_klucza' => 'x']],
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'replaces' => 'R1'],
        ]]);

        $this->assertCount(1, $ops->graphUpdates, 'the ending was refused');
        $this->assertSame('create', $ops->graphUpdates[0]['op']);
        $this->assertContains(KnowledgeGraphOps::REJECT_PROPERTIES, $this->codes($ops->rejected));

        // THE BINDING DID NOT OUTLIVE ITS PARTNER.
        $this->assertNull($ops->graphUpdates[0]['replaces']);
        $this->assertContains(KnowledgeGraphOps::WARN_REPLACES_UNBOUND, $this->codes($ops->warnings));
    }

    public function test_a_create_without_replaces_says_nothing(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of'],
        ]]);

        $this->assertNull($ops->graphUpdates[0]['replaces']);
        $this->assertSame([], $ops->warnings);
    }

    // ---- wiki updates -------------------------------------------------------------------------

    /**
     * A CONTENT CHANGE TO AN EXISTING ENTRY IS NOT AN OPERATION HERE ANY MORE.
     *
     * It used to be, and that was the hole: this channel reached the graph applier, which resolved the
     * entity by slug and wrote whenever ANY draft in the session was accepted — no review card, no
     * diff, no chance to refuse — while the SAME conceptual change arriving as `entries[].action=update`
     * went through the whole shadow machinery. One path walked around the other's safeguards.
     *
     * It becomes a shadow draft while the answer is laundered, and is reported here so the ops panel
     * can say the proposal MOVED rather than vanished.
     */
    public function test_a_content_change_to_an_existing_entry_leaves_this_channel(): void
    {
        $ops = $this->launder(['wiki_updates' => [
            ['entity' => 'E1', 'op' => 'append', 'section' => 'Kalendarium', 'content' => '- 2026-08-04: cos.'],
            ['entity' => 'E1', 'op' => 'rewrite', 'content' => 'Zupelnie nowa tresc.'],
        ]]);

        $this->assertSame([], $ops->wikiUpdates, 'nothing here may write an existing entry');
        $this->assertContains(KnowledgeGraphOps::WARN_MOVED_TO_REVIEW, $this->codes($ops->warnings));
    }

    /** A NEW entity's own initial text stays: it has no prior version, so there is nothing to review. */
    public function test_a_new_entitys_initial_content_survives(): void
    {
        $ops = $this->launder([
            'entities' => [['ref' => 'N1', 'title' => 'Bob', 'type' => 'person']],
            'wiki_updates' => [['entity' => 'N1', 'op' => 'append', 'content' => 'Bob jest nowy.']],
        ]);

        $this->assertCount(1, $ops->wikiUpdates);
        $this->assertSame('N1', $ops->wikiUpdates[0]['entity']);
        $this->assertSame('create', $ops->wikiUpdates[0]['op'], 'a new entity has nothing to append to');
        $this->assertSame('Bob jest nowy.', $ops->wikiUpdates[0]['content']);
    }

    public function test_an_invented_entity_handle_in_a_wiki_update_is_reported(): void
    {
        $ops = $this->launder(['wiki_updates' => [
            ['entity' => 'E9', 'op' => 'append', 'content' => 'Cos.'],
        ]]);

        $this->assertSame([], $ops->wikiUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_UNKNOWN_HANDLE, $this->codes($ops->rejected));
    }

    // ---- caps and malformed input ---------------------------------------------------------------

    public function test_the_operation_cap_stops_the_list_and_reports_it(): void
    {
        $updates = [];

        for ($i = 0; $i < 30; $i++) {
            $updates[] = ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'valid_from' => sprintf('2026-01-%02d', ($i % 28) + 1)];
        }

        $ops = $this->launder(['graph_updates' => $updates]);

        $this->assertCount(20, $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_CAP, $this->codes($ops->rejected));
    }

    public function test_a_non_array_answer_is_simply_empty(): void
    {
        $this->assertTrue(KnowledgeGraphOps::fromArray('not an object', $this->context(), new TemplateDirectiveGuard)->isEmpty());
        $this->assertTrue(KnowledgeGraphOps::fromArray(null, $this->context(), new TemplateDirectiveGuard)->isEmpty());
    }

    public function test_unknown_keys_do_not_exist(): void
    {
        $ops = $this->launder(['graph_updates' => [
            ['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of', 'sudo' => true, 'entry_id' => 'id-anna'],
        ]]);

        $this->assertCount(1, $ops->graphUpdates);
        $this->assertArrayNotHasKey('sudo', $ops->graphUpdates[0]);
        $this->assertArrayNotHasKey('entry_id', $ops->graphUpdates[0]);
    }

    public function test_an_unknown_op_is_reported_rather_than_guessed_at(): void
    {
        $ops = $this->launder(['graph_updates' => [['op' => 'merge', 'from' => 'E1', 'to' => 'E2', 'type' => 'knows']]]);

        $this->assertSame([], $ops->graphUpdates);
        $this->assertContains(KnowledgeGraphOps::REJECT_UNKNOWN_OP, $this->codes($ops->rejected));
    }

    // ---- ambiguity ------------------------------------------------------------------------------

    /** An ambiguity the model settled by USING one of the candidates needs no further question. */
    public function test_an_ambiguity_the_model_resolved_raises_no_warning(): void
    {
        $ops = $this->launder(
            ['graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'E2', 'type' => 'member_of']]],
            ['ambiguous' => ['Anna' => ['E1', 'E2']]],
        );

        $this->assertNotContains(KnowledgeGraphOps::WARN_AMBIGUOUS_UNRESOLVED, $this->codes($ops->warnings));
    }

    /** One the model ignored becomes a question for the human — detected here, not trusted from it. */
    public function test_an_ambiguity_the_model_ignored_is_raised_for_the_reviewer(): void
    {
        $ops = $this->launder(
            ['wiki_updates' => [['entity' => 'E1', 'op' => 'append', 'content' => 'Cos.']]],
            ['ambiguous' => ['Łukasz' => ['E7', 'E8']]],
        );

        // Searched rather than indexed: a run can raise several warnings, and pinning position 0 makes
        // a test that breaks whenever an unrelated one is added first.
        $warning = collect($ops->warnings)->firstWhere('code', KnowledgeGraphOps::WARN_AMBIGUOUS_UNRESOLVED);

        $this->assertNotNull($warning);
        $this->assertSame('Łukasz', $warning['mention']);
        $this->assertSame(['E7', 'E8'], $warning['candidates']);
    }
}
