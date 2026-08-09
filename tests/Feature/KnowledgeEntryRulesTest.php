<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Jobs\IndexKnowledgeEntryJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\DraftRunNotes;
use App\Modules\Knowledge\Support\EntryAliases;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\KnowledgeBaseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * THE RULES AN ENTRY IS SUBJECT TO — pinned on the paths that still exist.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT THIS FILE REPLACES, AND WHY IT IS NOT SIMPLY GONE
 *
 * This was `KnowledgeEntryCrudTest`, and it was three quarters a test of `POST`/`PATCH /entries` —
 * endpoints withdrawn when hand-authoring was: a person may approve, refuse and direct, but not write.
 * The obvious move was to delete the file with the endpoints. That would have been a silent regression,
 * because a CRUD test is where a module's DOMAIN rules end up living: the metadata contract, the
 * fail-closed refusal of template syntax, the size cap, the slug's stability and its uniqueness were all
 * asserted through a PATCH, but none of them is a property OF a PATCH. Every one still governs what may
 * enter a base — through the composer instead.
 *
 * So each rule was followed to wherever it is now enforced and re-pinned there:
 *
 *   THE SLUG        {@see \App\Modules\Knowledge\Services\KnowledgeEntryService} — minted, de-collided,
 *                   and deliberately deaf to a title change. Reached through the service, which is what
 *                   the composer and the applier call.
 *   METADATA        {@see \App\Modules\Knowledge\Services\KnowledgeDraftService::launder()} — the base's
 *                   descriptors, consulted per FIELD. The verdict CHANGED with the endpoint and the
 *                   change is asserted, not glossed: a bad field is now DROPPED, where a request was
 *                   refused whole.
 *   TEMPLATE SYNTAX same place, and still FAIL-CLOSED — the whole draft goes, because an entry is data
 *                   other features inject into prompts.
 *   THE SIZE CAP    same place, and it TRUNCATES where the request refused.
 *   ALIASES         {@see EntryAliases} — trimmed, deduped case-insensitively, capped at ten.
 *
 * Two rules did NOT survive and are recorded as losses rather than quietly dropped:
 *   - a REQUIRED metadata field can now be absent (the laundering has no notion of "missing"), and
 *   - the chunk-cap dry run that answered 422 before an over-cap entry was stored has no live caller
 *     at all — see {@see KnowledgeIndexingTest::test_an_entry_that_would_exceed_the_chunk_cap_is_not_indexed_and_buys_nothing()}.
 *
 * The read half of the old file — the list's excerpt, its filters and its query budget — is unchanged
 * and is kept here verbatim: those endpoints are alive and are now the module's whole HTTP surface for
 * entries.
 */
class KnowledgeEntryRulesTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    /** The session the last {@see compose()} ran, so a test can read what the SERVER said about it. */
    private ?KnowledgeDraftSession $session = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        app(TenantContext::class)->set($this->workspace);

        $this->base = KnowledgeBase::factory()
            ->withSchema([
                KnowledgeBaseFactory::field('zrodlo', VariableType::TEXT, nullable: true),
                KnowledgeBaseFactory::field('pewnosc', VariableType::NUMBER),
            ])
            ->create(['creator_id' => $this->user->id]);

        // NAMED, not inherited from `.env`. Nothing below cares which context-freezing pass runs — the
        // base starts empty, so both freeze nothing — but a test that reads the developer's flag is a
        // test that goes red for somebody else's reason.
        config()->set('knowledge.graph_extraction.enabled', false);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- driving the one write path there is ------------------------------------

    /**
     * Run the composer with a scripted reply and hand back the drafts it was allowed to store.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, KnowledgeEntry>
     */
    private function compose(array $entries): array
    {
        KnowledgeDraftAgent::fake(fn () => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE));

        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => 'Material zrodlowy, z ktorego kompozytor ma napisac wpisy.',
        ])->assertCreated()->json('data.id');

        $this->session = KnowledgeDraftSession::query()->findOrFail($id);

        return $this->session->drafts()->get()->all();
    }

    /**
     * The notes the last run recorded under one code.
     *
     * A rule enforced by DROPPING something is only half-tested by the drop: the other half is whether
     * anybody is told. Several rules in this file are silent by design (an alias trimmed, a metadata
     * key removed); the ones that discard a whole proposal are not allowed to be.
     *
     * @return array<int, array<string, mixed>>
     */
    private function notes(string $code): array
    {
        return array_values(array_filter(
            $this->session?->notes ?? [],
            static fn (array $note): bool => ($note['code'] ?? null) === $code,
        ));
    }

    /** @param  array<string, mixed>  $overrides */
    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'cennik',
            'title' => 'Cennik',
            'content' => 'Ceny obowiązują od stycznia.',
            'metadata' => ['zrodlo' => 'umowa', 'pewnosc' => 5],
        ], $overrides);
    }

    // ---- metadata against the base's schema -------------------------------------
    //
    // The descriptors are the same shared type authority the base's own schema is validated by; what
    // changed is the VERDICT. A request was refused whole and told the writer which field was wrong.
    // A model's reply has no writer to tell, so the laundering answers per field: what type-checks is
    // kept, the rest is dropped, and the draft still reaches the reviewer — who can see it.

    public function test_metadata_matching_the_schema_is_kept(): void
    {
        $drafts = $this->compose([$this->proposal(['metadata' => ['zrodlo' => 'umowa', 'pewnosc' => 3.5]])]);

        $this->assertCount(1, $drafts);
        $this->assertSame(['zrodlo' => 'umowa', 'pewnosc' => 3.5], $drafts[0]->metadata);
    }

    public function test_metadata_of_the_wrong_type_is_dropped_and_the_rest_of_the_entry_survives(): void
    {
        $drafts = $this->compose([$this->proposal(['metadata' => ['zrodlo' => 'umowa', 'pewnosc' => 'wysoka']])]);

        $this->assertCount(1, $drafts, 'one bad field must not cost the whole entry');
        $this->assertSame(['zrodlo' => 'umowa'], $drafts[0]->metadata);
    }

    public function test_an_undeclared_metadata_field_is_dropped(): void
    {
        $drafts = $this->compose([$this->proposal(['metadata' => [
            'zrodlo' => 'umowa',
            'pewnosc' => 1,
            'cokolwiek' => 'x',
        ]])]);

        $this->assertSame(['zrodlo' => 'umowa', 'pewnosc' => 1], $drafts[0]->metadata);
        $this->assertArrayNotHasKey('cokolwiek', $drafts[0]->metadata);
    }

    /**
     * CHARACTERIZED LOSS, asserted rather than left implicit.
     *
     * A non-nullable field used to be REQUIRED: `POST /entries` without `pewnosc` was a 422. The
     * laundering walks the descriptors and skips anything the reply did not mention, so a model that
     * omits a mandatory field now produces an entry without it. Pinned as the behaviour it is, so that
     * restoring the requirement is a deliberate act with a red test in front of it rather than a
     * surprise.
     */
    public function test_a_missing_non_nullable_metadata_field_is_no_longer_refused(): void
    {
        $drafts = $this->compose([$this->proposal(['metadata' => ['zrodlo' => 'umowa']])]);

        $this->assertCount(1, $drafts);
        $this->assertSame(['zrodlo' => 'umowa'], $drafts[0]->metadata);
    }

    /** A field whose VALUE smuggles template syntax loses the field — not the entry. */
    public function test_template_syntax_inside_metadata_costs_only_that_field(): void
    {
        $drafts = $this->compose([$this->proposal(['metadata' => [
            'zrodlo' => '{{ globals.zrodlo }}',
            'pewnosc' => 2,
        ]])]);

        $this->assertCount(1, $drafts);
        $this->assertSame(['pewnosc' => 2], $drafts[0]->metadata);
    }

    // ---- the fail-closed template-directive guard --------------------------------
    //
    // FAIL-CLOSED, and the asymmetry with metadata above is deliberate: a directive in the BODY or the
    // TITLE takes the whole draft, because the body is what gets injected into somebody else's prompt
    // and a half-scrubbed one is a document nobody wrote.

    public function test_template_syntax_in_the_body_costs_the_whole_draft(): void
    {
        $cases = [
            'editor directive' => 'Zobacz @[variable]("{\"v\":1}") tutaj.',
            'ai-text directive' => '@[ai-text]("{\"prompt\":\"x\"}")',
            'flat reference' => 'Marka to {{ globals.nazwa_marki }}.',
            'if-block fence' => "Tekst\n```if-block\n[[IF]]\ntak\n```\n",
            'branch marker' => "Tekst\n[[ELSE]]\ninne\n",
        ];

        foreach ($cases as $label => $content) {
            $drafts = $this->compose([$this->proposal(['content' => $content])]);

            $this->assertSame([], $drafts, 'expected ' . $label . ' to take the whole draft');
        }
    }

    public function test_template_syntax_in_the_title_costs_the_whole_draft(): void
    {
        $this->assertSame([], $this->compose([$this->proposal(['title' => 'Cennik {{ globals.rok }}'])]));
    }

    /** The guard is narrow on purpose: an ordinary `[[wikilink]]` is not a branch marker. */
    public function test_an_ordinary_wikilink_is_not_mistaken_for_template_syntax(): void
    {
        $drafts = $this->compose([$this->proposal([
            'content' => 'Patrz [[polityka-cenowa]] oraz [[rabaty|nasze rabaty]].',
        ])]);

        $this->assertCount(1, $drafts);
        $this->assertStringContainsString('[[rabaty|nasze rabaty]]', (string) $drafts[0]->content);
    }

    // ---- a proposal that is not an entry -------------------------------------------
    //
    // The oldest rule in the laundering — "an entry without a name or a body is not an entry" — and
    // until this batch the one drop in the whole pass that happened in SILENCE. ADR-0049 recorded it as
    // the least-covered of the four rules that lost their FormRequest: unreported in behaviour AND
    // unpinned in test, which is the pairing that lets a rule quietly stop working.

    /**
     * A PROPOSAL WITH NO TITLE IS DROPPED — and now says so.
     *
     * Three spellings of the same nothing, because a model can produce any of them: an absent key, an
     * empty string, and whitespace. `text()` trims before it judges, so all three are one case at the
     * only layer that matters.
     */
    public function test_a_proposal_without_a_title_is_dropped_and_reported(): void
    {
        $absent = $this->proposal();
        unset($absent['title']);

        foreach (['absent' => $absent, 'empty' => $this->proposal(['title' => '']), 'blank' => $this->proposal(['title' => "  \n "])] as $label => $proposal) {
            $this->assertSame([], $this->compose([$proposal]), 'expected a ' . $label . ' title to cost the draft');

            $reported = $this->notes(DraftRunNotes::ENTRY_INCOMPLETE);

            $this->assertCount(1, $reported, 'and the reviewer is told: ' . $label);
            $this->assertSame('title', $reported[0]['field']);
            $this->assertSame('cennik', $reported[0]['name'], 'the model\'s own slug is the only handle left');
        }
    }

    /** The same for a body: an entry that names a subject and says nothing about it is not an entry. */
    public function test_a_proposal_without_a_body_is_dropped_and_reported(): void
    {
        $this->assertSame([], $this->compose([$this->proposal(['content' => '   '])]));

        $reported = $this->notes(DraftRunNotes::ENTRY_INCOMPLETE);

        $this->assertCount(1, $reported);
        $this->assertSame('content', $reported[0]['field']);
    }

    /**
     * THE NOTE CARRIES NO `slug`, and that is not cosmetic.
     *
     * The client files a note naming a `slug` under THAT ENTRY'S CARD — which is right for every note
     * about a proposal that exists, and fatal for a note about one that was thrown away: there is no
     * card, so the note would render nowhere and the drop would be exactly as silent as before, one
     * layer further along. The handle travels as `name` for that reason.
     */
    public function test_the_drop_is_reported_against_the_run_and_not_a_card(): void
    {
        $this->compose([$this->proposal(['title' => ''])]);

        $reported = $this->notes(DraftRunNotes::ENTRY_INCOMPLETE);

        $this->assertCount(1, $reported);
        $this->assertArrayNotHasKey('slug', $reported[0]);
    }

    /** And it stays quiet on an ordinary proposal — a note on every run is a note nobody reads. */
    public function test_a_complete_proposal_raises_no_such_note(): void
    {
        $this->assertCount(1, $this->compose([$this->proposal()]));
        $this->assertSame([], $this->notes(DraftRunNotes::ENTRY_INCOMPLETE));
    }

    // ---- the size cap -------------------------------------------------------------

    /**
     * 40 000 characters, and the answer to breaching it CHANGED: the request refused, the laundering
     * clips. Refusing a model's over-long reply would throw away 40 000 usable characters over the
     * 40 001st, and there is no writer standing there to shorten it.
     */
    public function test_content_over_the_cap_is_truncated_rather_than_refused(): void
    {
        $cap = (int) config('knowledge.entry_max_chars');

        $this->assertSame(40000, $cap, 'the B1 cap is 40000 characters');

        $atTheCap = $this->compose([$this->proposal(['content' => str_repeat('a', $cap)])]);
        $this->assertSame($cap, mb_strlen((string) $atTheCap[0]->content));

        $overTheCap = $this->compose([$this->proposal([
            'slug' => 'za-dlugi',
            'title' => 'Za długi',
            'content' => str_repeat('b', $cap + 500),
        ])]);

        $this->assertCount(1, $overTheCap, 'an over-long reply is clipped, not thrown away');
        $this->assertSame($cap, mb_strlen((string) $overTheCap[0]->content));
    }

    // ---- aliases ------------------------------------------------------------------

    /**
     * {@see EntryAliases} is the one normalizer, and the composer is the caller that reaches it now.
     * Trimming and case-insensitive de-duplication remove nothing the writer meant; the count cap DROPS
     * rather than refuses, for the reason stated in that class.
     */
    public function test_aliases_are_trimmed_deduplicated_and_capped(): void
    {
        $drafts = $this->compose([$this->proposal([
            'aliases' => array_merge(
                ['  Wieża  ', 'wieża', 'WIEŻA', 'Eiffel Tower'],
                array_map(fn (int $i): string => 'Forma ' . $i, range(1, 12)),
            ),
        ])]);

        $aliases = $drafts[0]->aliases;

        $this->assertSame(['Wieża', 'Eiffel Tower'], array_slice($aliases, 0, 2));
        $this->assertCount(EntryAliases::MAX, $aliases);
    }

    // ---- the slug -----------------------------------------------------------------
    //
    // The load-bearing rules of the whole module: a slug is the address a `[[wikilink]]` resolves
    // through, so one that followed its title would break every inbound edge the moment somebody fixed
    // a typo in a heading. Asserted against the SERVICE, which is what mints one for a composed entry
    // and for an accepted proposal alike.

    public function test_a_new_entry_gets_a_handle_a_first_revision_and_a_place_in_the_index_queue(): void
    {
        // The queue is faked so the entry can be caught in the state it is SAVED in. Without it the
        // observer's after-commit job runs inline against the fake embedder and the entry is already
        // indexed by the time the assertion reads it — which would prove the indexer works, not that
        // creation stamps the entry for it.
        Queue::fake();

        $entry = $this->makeEntry($this->base, 'Cennik', 'Ceny.');

        $this->assertSame('cennik', $entry->slug);
        $this->assertSame((string) $this->user->id, (string) $entry->creator_id);
        $this->assertSame(1, $entry->revisions()->count(), 'creation is itself the first version');
        $this->assertSame($entry->current_revision_id, (string) $entry->revisions()->value('id'));

        $this->assertSame(KnowledgeIndexStatus::PENDING, $entry->fresh()->index_status);
        $this->assertTrue($entry->fresh()->needsIndexing());

        Queue::assertPushed(IndexKnowledgeEntryJob::class);
    }

    public function test_the_slug_does_not_follow_a_title_change(): void
    {
        $entry = $this->makeEntry($this->base, 'Cennik', 'Ceny.');

        $this->updateEntry($entry, title: 'Zupełnie nowy tytuł');

        $this->assertSame('Zupełnie nowy tytuł', (string) $entry->fresh()->title);
        $this->assertSame('cennik', (string) $entry->fresh()->slug);
    }

    public function test_a_colliding_title_gets_a_suffixed_slug(): void
    {
        $this->assertSame('cennik', $this->makeEntry($this->base, 'Cennik')->slug);
        $this->assertSame('cennik-2', $this->makeEntry($this->base, 'Cennik')->slug);
        $this->assertSame('cennik-3', $this->makeEntry($this->base, 'Cennik')->slug);
    }

    public function test_a_title_that_slugs_to_nothing_still_gets_a_handle(): void
    {
        $this->assertSame('entry', $this->makeEntry($this->base, '🙂🙂')->slug);
    }

    /**
     * An explicit rename DOES move the handle — the composer sets `slug` when it declares an entity, and
     * a refinement re-states it, so this is a live path and not a leftover of the editor.
     */
    public function test_an_explicit_rename_changes_the_slug(): void
    {
        $entry = $this->makeEntry($this->base, 'Cennik');

        $this->updateEntry($entry, slug: 'polityka-cenowa');

        $this->assertSame('polityka-cenowa', (string) $entry->fresh()->slug);
    }

    /**
     * A COMPOSED slug never lands on a taken one — de-collided against live entries and against other
     * sessions' drafts before it is written.
     *
     * This is what the removed `unique` rule on `PATCH /entries` used to say, said where the addresses
     * now come from. It matters more here than it did there: a duplicate slug inside one base makes
     * `[[cennik]]` ambiguous, and nothing downstream could pick the right one.
     */
    public function test_a_composed_slug_is_de_collided_against_the_entries_already_in_the_base(): void
    {
        $this->makeEntry($this->base, 'Cennik', 'Ceny obowiązujące dziś.');

        $drafts = $this->compose([$this->proposal(['slug' => 'cennik', 'title' => 'Cennik'])]);

        $this->assertCount(1, $drafts);
        $this->assertSame('cennik-2', (string) $drafts[0]->slug);
        $this->assertSame(
            1,
            KnowledgeEntry::query()->withDrafts()->where('knowledge_base_id', $this->base->id)->where('slug', 'cennik')->count(),
            'one address, one entry',
        );
    }

    // ---- what a reader still gets over HTTP ---------------------------------------

    public function test_the_list_carries_an_excerpt_instead_of_the_body(): void
    {
        $this->makeEntry($this->base, 'Cennik', str_repeat('a', 5000));

        $response = $this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk();

        $this->assertArrayNotHasKey('content', $response->json('data.0'));
        $this->assertLessThan(300, mb_strlen($response->json('data.0.excerpt')));
    }

    public function test_the_list_filters_by_status_and_staleness_and_text(): void
    {
        KnowledgeEntry::factory()->slugged('a')->status(KnowledgeEntryStatus::APPROVED)
            ->create(['knowledge_base_id' => $this->base->id, 'title' => 'Zatwierdzony cennik']);
        KnowledgeEntry::factory()->slugged('b')->status(KnowledgeEntryStatus::DRAFT)->stale()
            ->create(['knowledge_base_id' => $this->base->id, 'title' => 'Przeterminowany szkic']);

        $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?status[]=approved")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'a');

        $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?stale=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'b')
            ->assertJsonPath('data.0.is_stale', true);

        $this->getJson("/api/knowledge/bases/{$this->base->id}/entries?search=szkic")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'b');
    }

    // ---- B7: the list's query budget ---------------------------------------------

    /**
     * THE LIST COSTS THE SAME NUMBER OF QUERIES WHATEVER IS IN IT.
     *
     * Two things on {@see \App\Modules\Knowledge\Http\Resources\KnowledgeEntryListResource} are per-row
     * derivations, and both are the shape that becomes an N+1 the moment somebody reads them off the
     * relation instead of the page:
     *
     *   the AUTHOR, eager-loaded through `with('creator')`;
     *   the INDEX PROGRESS numerator, which is a count of the entry's usable chunks and is fetched as
     *     one correlated sub-select for the whole page ({@see KnowledgeEntry::scopeWithIndexedChunks()}).
     *
     * The second is the fragile one. It is a `withCount` with a closure on it, so it is easy to
     * "simplify" into `$entry->chunks()->indexed()->count()` inside the resource — which produces
     * identical JSON, passes every other test in this file, and turns a 25-row page into 26 queries
     * against the largest table in the module.
     *
     * Asserted as a comparison between two page sizes rather than against a fixed number, so the
     * budget is not re-baselined by an unrelated middleware query and cannot rot into a magic constant.
     */
    public function test_the_entry_list_query_count_does_not_grow_with_the_page(): void
    {
        $measure = function (int $entries): int {
            $base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

            for ($i = 1; $i <= $entries; $i++) {
                KnowledgeEntry::factory()
                    ->slugged("wpis-{$i}")
                    ->create([
                        'knowledge_base_id' => $base->id,
                        'title' => "Wpis {$i}",
                        // A distinct author per row: one shared creator would let an N+1 hide behind
                        // Eloquent's identity map.
                        'creator_id' => User::factory()->create()->id,
                    ]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $this->getJson("/api/knowledge/bases/{$base->id}/entries")
                    ->assertOk()
                    ->assertJsonCount($entries, 'data');

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };

        $small = $measure(2);
        $large = $measure(12);

        $this->assertSame(
            $small,
            $large,
            "listing 12 entries cost {$large} queries and listing 2 cost {$small} — the list is N+1",
        );
    }
}
