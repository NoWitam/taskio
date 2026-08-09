<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\DTOs\KnowledgeRelationDTO;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationOrigin;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Exceptions\KnowledgeRelationRefused;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Models\KnowledgeRelationEvent;
use App\Modules\Knowledge\Services\KnowledgeRelationService;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G2 — TYPED RELATIONS: the approved statements a base records about pairs of entries.
 *
 * The three properties worth stating up front, because everything below defends one of them:
 *
 *   RELATIONS ARE NOT LINKS. Links are a cache the module rebuilds on every save; relations were
 *   asserted by somebody and approved. They live in their own table so no sweep can reach them, and
 *   the machine-facing contract has no delete at all — ending and retracting KEEP the row.
 *
 *   THE TYPE MATRIX IS ADVISORY WHERE THE TYPE IS UNKNOWN. Every entry written before `entry_type`
 *   existed is untyped, so refusing on a guess would reject correct relations across a whole base.
 *   Only a pair where BOTH ends are typed and the verb does not join them is refused.
 *
 *   SYMMETRIC VERBS ARE STORED ONCE. `knows` between A and B is one row in a canonical direction,
 *   drawn undirected — two rows could disagree with each other and nothing could say which was right.
 *
 * ------------------------------------------------------------------------------------------------
 * THE GATES MOVED LAYER, NOT MEANING
 *
 * `POST`, `PATCH`, `/end` and `DELETE /relations` are gone: a relation is proposed by the composer and
 * approved by a person, and there is no other way one gets written. Every gate below used to be asserted
 * as a 422 with a `code`; each is now asserted as the {@see KnowledgeRelationRefused} the service throws,
 * which is the SAME barrier — the endpoint only ever rendered it. That is not a downgrade: the applier
 * re-runs all four gates at accept time precisely because the base can move while a proposal sits on a
 * reviewer's screen, so this class is the layer that has to hold, and it is the layer under test.
 *
 * Two of the file's old tests had no equivalent left and were removed rather than reshaped — see the
 * note above {@see test_a_relation_is_written_with_composer_origin()}.
 */
class KnowledgeRelationTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------

    private function entry(string $title, ?KnowledgeEntryType $type = null): KnowledgeEntry
    {
        return $this->makeEntry(
            base: $this->base,
            title: $title,
            content: 'Tresc wpisu ' . $title . '.',
            type: $type,
        );
    }

    /** @param array<string, mixed> $extra description/properties/valid_from/valid_to */
    private function relate(
        KnowledgeEntry $from,
        KnowledgeEntry $to,
        KnowledgeRelationType $type,
        array $extra = [],
        ?KnowledgeBase $base = null,
    ): KnowledgeRelation {
        return $this->makeRelation(
            base: $base ?? $this->base,
            from: $from,
            to: $to,
            type: $type,
            description: $extra['description'] ?? null,
            properties: $extra['properties'] ?? [],
            validFrom: isset($extra['valid_from']) ? Carbon::parse($extra['valid_from']) : null,
            validTo: isset($extra['valid_to']) ? Carbon::parse($extra['valid_to']) : null,
        );
    }

    /**
     * The refusal a gate raises, captured so the test can read its reason and its context.
     *
     * Returned rather than asserted with `expectException`, because every one of these tests also has
     * something to say about what did NOT happen — no row written, no event logged — and an expectation
     * declared up front ends the test at the throw.
     */
    private function refusal(callable $write): KnowledgeRelationRefused
    {
        try {
            $write();
        } catch (KnowledgeRelationRefused $refused) {
            return $refused;
        }

        $this->fail('the write was accepted; a refusal was expected');
    }

    // ---- schema ----------------------------------------------------------------------

    public function test_the_relation_tables_exist_with_their_columns(): void
    {
        $this->assertTrue(Schema::hasTable('knowledge_relations'));
        $this->assertTrue(Schema::hasTable('knowledge_relation_events'));

        foreach ([
            'workspace_id', 'knowledge_base_id', 'from_entry_id', 'to_entry_id', 'relation_type',
            'description', 'properties', 'valid_from', 'valid_to', 'state', 'superseded_by_id',
            'origin', 'draft_session_id', 'creator_id', 'creator_type',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('knowledge_relations', $column), "knowledge_relations.{$column}");
        }

        // The typed-relation columns on the tables this layer does not own.
        $this->assertTrue(Schema::hasColumn('knowledge_entries', 'entry_type'));
        $this->assertTrue(Schema::hasColumn('knowledge_bases', 'relation_types'));

        foreach (['resolution_set', 'graph_ops', 'applied_ops', 'notes'] as $column) {
            $this->assertTrue(Schema::hasColumn('knowledge_draft_sessions', $column), "sessions.{$column}");
        }

        // Append-only, exactly like a revision: no updated_at on the audit trail.
        $this->assertFalse(Schema::hasColumn('knowledge_relation_events', 'updated_at'));
    }

    /** A self-relation is never a fact — enforced by the database, not only by the service. */
    public function test_a_self_relation_is_refused_by_the_database(): void
    {
        $entry = $this->entry('Anna');

        $this->expectExceptionMessageMatches('/knowledge_relations_not_self/');

        KnowledgeRelation::query()->create([
            'knowledge_base_id' => $this->base->id,
            'from_entry_id' => $entry->id,
            'to_entry_id' => $entry->id,
            'relation_type' => KnowledgeRelationType::PART_OF->value,
            'origin' => 'human',
        ]);
    }

    // ---- the write path ----------------------------------------------------------------

    /**
     * THE ORIGIN IS `composer`, AND THAT IS THE HEADLINE OF THIS WHOLE FILE.
     *
     * It read `human` until hand-authorship was withdrawn, because a person could assert a relation
     * directly. Nobody can now, so every new row is something a model proposed and a person approved —
     * and `origin` is what lets the base tell the two apart later, which is why it is asserted here
     * rather than left to the enum's own test.
     *
     * TWO OLD TESTS DIED HERE AND ARE NOT COMING BACK: `promoting a suggestion creates the relation and
     * dismisses the link` and `promoting a wikilink leaves the link standing`. Promotion — turning a
     * machine-guessed edge into an approved statement in one click — was reachable only through
     * `POST /relations` with `promote_link_id`, and {@see KnowledgeRelationService::create()} no longer
     * has the branch at all — nor an `OP_PROMOTE` constant, since the historical rows it was kept for
     * turned out not to exist. There is no live path to re-point them at.
     */
    public function test_a_relation_is_written_with_composer_origin(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $relation = $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF, [
            'description' => 'Od pierwszego dnia projektu.',
            'properties' => ['role' => 'CTO'],
            'valid_from' => '2026-01-15',
        ]);

        $this->assertSame(KnowledgeRelationType::MEMBER_OF, $relation->relation_type);
        $this->assertSame(KnowledgeRelationState::ACTIVE, $relation->state);
        $this->assertSame(KnowledgeRelationOrigin::COMPOSER, $relation->origin);
        $this->assertSame(['role' => 'CTO'], $relation->properties);
        $this->assertSame('2026-01-15', $relation->valid_from->toDateString());

        // The same row is reachable from BOTH entries — a relation panel asks "what do we know about
        // this entry", which does not distinguish subject from object.
        $this->getJson("/api/knowledge/entries/{$anna->id}/relations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origin', 'composer')
            ->assertJsonPath('data.0.symmetric', false);

        $this->getJson("/api/knowledge/entries/{$acme->id}/relations")->assertOk()->assertJsonCount(1, 'data');

        // ...and the audit trail recorded that it was asserted.
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_CREATE)->count());
    }

    /**
     * The verb is read forwards AND backwards, so a panel anchored on either end words the same row
     * correctly.
     *
     * Asserted as "two DIFFERENT non-empty readings" rather than against fixed strings, because the
     * labels are translated and pinning one language would make this test a copy of the lang file. The
     * property that matters is that the inverse is not derivable from the forward form — which is
     * exactly why the server sends both — and that is what a difference proves.
     */
    public function test_both_readings_of_the_verb_are_sent(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $body = $this->getJson("/api/knowledge/entries/{$anna->id}/relations")->assertOk()->json('data.0');

        $this->assertNotSame('', $body['label']);
        $this->assertNotSame('', $body['inverse_label']);
        $this->assertNotSame($body['label'], $body['inverse_label']);
        // Not the raw key leaking through a missing translation, which is how an untranslated enum
        // usually shows up.
        $this->assertStringNotContainsString('knowledge.relation_types', $body['label']);
    }

    /** Every verb in the vocabulary is translated in BOTH directions, in both shipped languages. */
    public function test_every_relation_verb_is_translated_in_both_directions(): void
    {
        foreach (['en', 'pl'] as $locale) {
            app()->setLocale($locale);

            foreach (KnowledgeRelationType::cases() as $type) {
                $this->assertStringNotContainsString('knowledge.relation_types', $type->label(), "{$locale}/{$type->value}");
                $this->assertStringNotContainsString('knowledge.relation_types', $type->inverseLabel(), "{$locale}/{$type->value} inverse");
            }

            foreach (KnowledgeEntryType::cases() as $type) {
                $this->assertStringNotContainsString('knowledge.entry_types', $type->label(), "{$locale}/{$type->value}");
            }
        }
    }

    /**
     * THE MATRIX, both halves. A typed pair the verb does not join is refused; the SAME verb between
     * untyped entries is accepted, because an untyped base is the normal state and refusing on a guess
     * would reject correct relations wholesale.
     */
    public function test_a_typed_pair_outside_the_matrix_is_refused(): void
    {
        $warsaw = $this->entry('Warszawa', KnowledgeEntryType::PLACE);
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);

        $refused = $this->refusal(fn () => $this->relate($warsaw, $anna, KnowledgeRelationType::WORKS_ON));

        $this->assertSame(KnowledgeRelationRefused::PAIR, $refused->reason);
        $this->assertSame('place', $refused->context['from_entry_type']);
        $this->assertSame('person', $refused->context['to_entry_type']);
        $this->assertSame(0, KnowledgeRelation::query()->count());
    }

    public function test_the_same_pair_is_accepted_when_a_type_is_unknown(): void
    {
        // Exactly the shape of every entry written before entry_type existed.
        $untyped = $this->entry('Warszawa');
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);

        $this->relate($untyped, $anna, KnowledgeRelationType::WORKS_ON);

        $this->assertSame(1, KnowledgeRelation::query()->count());
    }

    /**
     * THE SERVICE BARRIER SURVIVES — the laundering check did not replace it.
     *
     * The reversed-date rule is enforced in two places on purpose. Laundering refuses it so the run
     * reports it BEFORE the reviewer decides ({@see \App\Modules\Knowledge\Support\KnowledgeGraphOps}
     * REJECT_DATES, pinned in `KnowledgeGraphOpsTest`); the service refuses it so anything that reaches
     * the service another way is covered — including the applier, which re-runs every gate at accept
     * time against a base that may have moved. Pinned because a later reader who meets the rule in the
     * launderer might reasonably conclude this copy is redundant. It is not.
     */
    public function test_a_relation_that_ends_before_it_begins_is_refused_by_the_service(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $refused = $this->refusal(fn () => app(KnowledgeRelationService::class)->create($this->base, new KnowledgeRelationDTO(
            fromEntryId: (string) $anna->id,
            toEntryId: (string) $acme->id,
            type: KnowledgeRelationType::MEMBER_OF,
            description: null,
            properties: [],
            validFrom: Carbon::parse('2026-07-01'),
            validTo: Carbon::parse('2024-01-01'),
            origin: KnowledgeRelationOrigin::COMPOSER,
        )));

        $this->assertSame(KnowledgeRelationRefused::DATES, $refused->reason);
        $this->assertSame(0, KnowledgeRelation::query()->count());
    }

    /** `other` is the writer saying "no class applies", so the matrix must not conclude from it. */
    public function test_an_other_typed_entry_is_treated_as_unknown(): void
    {
        $thing = $this->entry('Cos', KnowledgeEntryType::OTHER);
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);

        $this->relate($thing, $anna, KnowledgeRelationType::WORKS_ON);

        $this->assertSame(1, KnowledgeRelation::query()->count());
    }

    public function test_a_verb_the_base_does_not_allow_is_refused(): void
    {
        $this->base->forceFill(['relation_types' => ['member_of']])->save();

        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $bob = $this->entry('Bob', KnowledgeEntryType::PERSON);

        $refused = $this->refusal(fn () => $this->relate($anna, $bob, KnowledgeRelationType::KNOWS));

        $this->assertSame(KnowledgeRelationRefused::VOCABULARY, $refused->reason);

        // ...and the allowed one still works.
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);
        $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $this->assertSame(1, KnowledgeRelation::query()->count());
    }

    /**
     * NULL and `[]` are different answers: never-configured means the whole vocabulary, an empty list
     * means none. A base that has not thought about relation types must not end up stricter than one
     * whose owner deliberately allowed nothing.
     */
    public function test_an_unconfigured_base_allows_the_whole_vocabulary(): void
    {
        $this->assertNull($this->base->fresh()->relation_types);

        $this->getJson("/api/knowledge/bases/{$this->base->id}")
            ->assertOk()
            ->assertJsonCount(count(KnowledgeRelationType::cases()), 'data.relation_types')
            ->assertJsonCount(count(KnowledgeRelationType::cases()), 'data.relation_vocabulary');
    }

    public function test_an_empty_allow_list_permits_nothing(): void
    {
        $this->base->forceFill(['relation_types' => []])->save();

        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $refused = $this->refusal(fn () => $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF));

        $this->assertSame(KnowledgeRelationRefused::VOCABULARY, $refused->reason);
    }

    public function test_a_property_the_verb_does_not_declare_is_refused_rather_than_dropped(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $refused = $this->refusal(fn () => $this->relate(
            $anna,
            $acme,
            KnowledgeRelationType::MEMBER_OF,
            ['properties' => ['salary' => 1000]],
        ));

        $this->assertSame(KnowledgeRelationRefused::PROPERTY, $refused->reason);
        $this->assertSame('salary', $refused->context['property']);
        $this->assertSame(0, KnowledgeRelation::query()->count());
    }

    // ---- duplicates and caps ---------------------------------------------------------

    public function test_an_identical_active_relation_is_refused(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $first = $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $refused = $this->refusal(fn () => $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF));

        $this->assertSame(KnowledgeRelationRefused::DUPLICATE, $refused->reason);
        $this->assertSame((string) $first->id, $refused->context['existing_relation_id']);
    }

    /**
     * A DIFFERENT START DATE is a different fact, not a duplicate — which is exactly why the schema
     * has no unique index on (from, to, type). "Met on 15 August" and "met on 12 September" are two
     * meetings.
     */
    public function test_the_same_verb_on_a_different_date_is_a_separate_fact(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $bob = $this->entry('Bob', KnowledgeEntryType::PERSON);

        $this->relate($anna, $bob, KnowledgeRelationType::KNOWS, ['valid_from' => '2026-08-15']);
        $this->relate($anna, $bob, KnowledgeRelationType::KNOWS, ['valid_from' => '2026-09-12']);

        $this->assertSame(2, KnowledgeRelation::query()->count());
    }

    /** An ENDED relation does not block re-asserting the same fact — it stopped being asserted. */
    public function test_ending_a_relation_frees_the_pair_for_a_new_one(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $this->endRelation($this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF));

        $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $this->assertSame(2, KnowledgeRelation::query()->count());
    }

    public function test_the_per_entry_cap_is_enforced_on_both_ends(): void
    {
        config()->set('knowledge.relations.max_relations_per_entry', 2);

        $hub = $this->entry('Hub');

        for ($i = 0; $i < 2; $i++) {
            $this->relate($hub, $this->entry('Other ' . $i), KnowledgeRelationType::PART_OF);
        }

        $refused = $this->refusal(fn () => $this->relate($hub, $this->entry('One more'), KnowledgeRelationType::PART_OF));

        $this->assertSame(KnowledgeRelationRefused::CAP, $refused->reason);

        // ...and the cap counts only ACTIVE relations: history must not fill an entry's budget, or a
        // long-lived entry would eventually become impossible to say anything new about.
        KnowledgeRelation::query()->where('from_entry_id', $hub->id)->update(['state' => KnowledgeRelationState::ENDED->value]);

        $this->relate($hub, $this->entry('After the history'), KnowledgeRelationType::PART_OF);

        $this->assertSame(3, KnowledgeRelation::query()->count());
    }

    // ---- symmetry ----------------------------------------------------------------------

    /**
     * A symmetric verb is ONE row in a canonical direction. Two rows could disagree — one ended, one
     * not — with nothing to say which is right.
     */
    public function test_a_symmetric_relation_is_stored_once_in_a_canonical_direction(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $bob = $this->entry('Bob', KnowledgeEntryType::PERSON);

        $this->relate($anna, $bob, KnowledgeRelationType::KNOWS);

        // Asserted the OTHER WAY ROUND, it is the same claim — and is refused as a duplicate.
        $this->assertSame(
            KnowledgeRelationRefused::DUPLICATE,
            $this->refusal(fn () => $this->relate($bob, $anna, KnowledgeRelationType::KNOWS))->reason,
        );

        $relation = KnowledgeRelation::query()->firstOrFail();

        // Smaller id first, whichever end the writer named.
        [$expectedFrom, $expectedTo] = strcmp((string) $anna->id, (string) $bob->id) <= 0
            ? [$anna->id, $bob->id]
            : [$bob->id, $anna->id];

        $this->assertSame((string) $expectedFrom, (string) $relation->from_entry_id);
        $this->assertSame((string) $expectedTo, (string) $relation->to_entry_id);

        // Rendered from BOTH ends regardless, flagged so the UI draws it undirected.
        $this->getJson("/api/knowledge/entries/{$anna->id}/relations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.symmetric', true);

        $this->getJson("/api/knowledge/entries/{$bob->id}/relations")->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- the lifecycle -------------------------------------------------------------------

    public function test_ending_keeps_the_row_and_records_when_it_stopped(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $relation = $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $ended = $this->endRelation($relation, Carbon::parse('2026-03-31'));

        $this->assertSame(KnowledgeRelationState::ENDED, $ended->state);
        $this->assertSame('2026-03-31', $ended->valid_to->toDateString());
        $this->assertFalse($ended->isActive());

        $this->assertNotNull(KnowledgeRelation::query()->find($relation->id), 'ending must never delete the row');
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_END)->count());

        // ...and the reader is shown it as history rather than as a standing fact.
        $this->getJson("/api/knowledge/entries/{$anna->id}/relations?include_historical=1")
            ->assertOk()
            ->assertJsonPath('data.0.state', 'ended')
            ->assertJsonPath('data.0.valid_to', '2026-03-31')
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_retracting_says_it_was_never_true(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $retracted = $this->retractRelation($this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF));

        $this->assertSame(KnowledgeRelationState::RETRACTED, $retracted->state);
        // No end DATE: a claim that was never true did not stop being true on a particular day.
        $this->assertNull($retracted->valid_to);
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_RETRACT)->count());
    }

    public function test_superseding_points_the_old_relation_at_its_replacement(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);
        $other = $this->entry('Inna firma', KnowledgeEntryType::ORGANIZATION);

        $old = $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);
        $new = $this->relate($anna, $other, KnowledgeRelationType::MEMBER_OF);

        $superseded = $this->supersedeRelation($old, $new);

        $this->assertSame(KnowledgeRelationState::ENDED, $superseded->state);
        $this->assertSame((string) $new->id, (string) $superseded->superseded_by_id);
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_SUPERSEDE)->count());
    }

    /**
     * The description and dates are editable; the two ends and the verb are not.
     *
     * The SIGNATURE is the contract now. The endpoint used to ignore `to_entry_id` and `relation_type`
     * in its payload; {@see KnowledgeRelationService::update()} simply has no parameter for either, so
     * a caller cannot express the change — which is a stronger form of the same rule, and the reason
     * this test asserts the row is unmoved rather than asserting a request was ignored.
     */
    public function test_an_edit_changes_the_statement_but_not_what_it_is_about(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $relation = $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        $updated = $this->updateRelation($relation, description: 'Poprawiony opis.');

        $this->assertSame('Poprawiony opis.', $updated->description);
        $this->assertSame((string) $acme->id, (string) $updated->to_entry_id);
        $this->assertSame(KnowledgeRelationType::MEMBER_OF, $updated->relation_type);
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('op', KnowledgeRelationEvent::OP_UPDATE)->count());
    }

    /**
     * DELETE still exists and is reachable from exactly one place — `knowledge:purge-subject`, erasing
     * the statements that name somebody who asked to be forgotten. It is on no route and no composer
     * path, so this asserts the service directly, which is where the erasure command reaches it.
     */
    public function test_deleting_destroys_the_relation_but_keeps_the_audit_line(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $relation = $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);
        $id = (string) $relation->id;

        $this->deleteRelation($relation);

        $this->assertNull(KnowledgeRelation::query()->find($id));
        // The log has no FK precisely so the record of the deletion survives the deletion.
        $this->assertSame(1, KnowledgeRelationEvent::query()->where('relation_id', $id)->where('op', KnowledgeRelationEvent::OP_DELETE)->count());
    }

    // ---- reads ----------------------------------------------------------------------------

    public function test_historical_relations_are_hidden_unless_asked_for(): void
    {
        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);

        $this->endRelation($this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF));

        $this->getJson("/api/knowledge/entries/{$anna->id}/relations")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/knowledge/entries/{$anna->id}/relations?include_historical=1")->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- tenancy and authorization ------------------------------------------------------------

    /**
     * Another workspace's relation is NOT FOUND, asserted on the one route that can still reach one.
     *
     * The mutation routes this used to exercise are gone; the READ is the surviving surface and it is
     * the one that matters here anyway, because the leak being guarded against is a foreign id
     * resolving at all. The 404 comes from the middleware ORDER (ResolveWorkspace before
     * SubstituteBindings), which is pinned app-wide by ApiMiddlewarePriorityTest.
     */
    public function test_another_workspaces_entry_and_its_relations_are_not_found(): void
    {
        $stranger = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $other->users()->attach($stranger->id);

        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $acme = $this->entry('Acme', KnowledgeEntryType::ORGANIZATION);
        $this->relate($anna, $acme, KnowledgeRelationType::MEMBER_OF);

        // Same suite, DIFFERENT workspace header: the binding must not resolve at all.
        $this->actingAs($stranger)->withHeader('X-Workspace-Id', $other->id);
        app(TenantContext::class)->set($other);

        $this->getJson("/api/knowledge/entries/{$anna->id}/relations")->assertNotFound();
        $this->getJson("/api/knowledge/entries/{$anna->id}")->assertNotFound();
    }

    /** An endpoint in ANOTHER base is not found — a relation must never stitch two bases together. */
    public function test_an_endpoint_from_another_base_is_not_found(): void
    {
        $otherBase = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);

        $anna = $this->entry('Anna', KnowledgeEntryType::PERSON);
        $foreign = $this->makeEntry(base: $otherBase, title: 'Acme', type: KnowledgeEntryType::ORGANIZATION);

        // The service aborts 404 rather than writing an edge across two bases — the same refusal the
        // endpoint used to render, raised where the endpoints resolved.
        $this->expectException(NotFoundHttpException::class);

        $this->relate($anna, $foreign, KnowledgeRelationType::MEMBER_OF);
    }
}
