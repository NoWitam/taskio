<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Models\KnowledgeRelationEvent;
use App\Modules\Knowledge\Services\KnowledgeBaseService;
use App\Modules\Knowledge\Services\KnowledgeSubjectPurgeService;
use App\Modules\Knowledge\Support\SubjectPhrases;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * B6b — `knowledge:purge-subject`, the erasure-request command.
 *
 * What is being defended here is not "rows disappear" — a DELETE does that. It is the set of
 * properties that make an IRREVERSIBLE, operator-run delete safe to hand someone:
 *
 *   the default run destroys nothing, and reports everything (so the dry run is worth reading);
 *   append-only HISTORY is reachable, at revision granularity, without taking the entry with it
 *     (this is the entire reason the command exists — editing a name out does not erase it);
 *   a ghost slug that IS the person's name is deleted, while the module's standing "degrade, never
 *     delete" rule survives everywhere else (a narrowed exception, not an abandoned rule);
 *   an over-broad phrase is refused rather than obeyed;
 *   and the phrase — which is itself the personal data — never lands in the application log.
 *
 * The log assertion listens to every MessageLogged event rather than mocking one call, because the
 * property is "no log line anywhere contains it", and a mock can only ever check the lines someone
 * remembered to mock.
 */
class KnowledgePurgeSubjectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        app(TenantContext::class)->set($this->workspace);

        // These fixtures are about TEXT, not vectors. Left on, the entry observer would chunk and
        // (fake-)embed every factory row on the sync queue, so the chunk counts asserted below would
        // describe the chunker's behaviour rather than what the test actually created.
        config(['knowledge.index.enabled' => false]);

        $this->base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers --------------------------------------------------------------

    /**
     * @param  list<string>  $phrases
     * @param  array<string, mixed>  $options
     */
    private function purge(array $phrases, array $options = []): int
    {
        $exit = Artisan::call('knowledge:purge-subject', array_merge([
            'workspace' => $this->workspace->id,
            'phrase' => $phrases,
        ], $options));

        // The command drops tenancy on the way out (as every console entry point in this codebase
        // does), so a test that keeps building fixtures after one has run must re-establish the
        // context it was using — otherwise the next factory row is written with no workspace at all.
        app(TenantContext::class)->set($this->workspace);

        return $exit;
    }

    /**
     * The `--json` dry-run report, decoded. Kept next to {@see purge()} because it shares its one
     * non-obvious duty: re-establishing tenancy after the command drops it.
     *
     * @param  list<string>  $phrases
     * @return array<string, mixed>
     */
    private function jsonReport(array $phrases): array
    {
        $this->purge($phrases, ['--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    private function entry(string $slug, string $content, array $attributes = []): KnowledgeEntry
    {
        return KnowledgeEntry::factory()
            ->slugged($slug)
            ->withContent($content)
            ->create(array_merge([
                'knowledge_base_id' => $this->base->id,
                'creator_id' => $this->user->id,
            ], $attributes));
    }

    /** @return array{entries:int, revisions:int, links:int, chunks:int} */
    private function census(): array
    {
        return [
            'entries' => KnowledgeEntry::withTrashed()->count(),
            'revisions' => KnowledgeEntryRevision::query()->count(),
            'links' => KnowledgeLink::query()->count(),
            'chunks' => KnowledgeEntryChunk::query()->count(),
        ];
    }

    // ---- C-1: the dry run is the default --------------------------------------

    public function test_the_default_run_reports_every_category_and_deletes_nothing(): void
    {
        $named = $this->entry('raport-q3', 'Ustalenia ze spotkania z Kowalska.');
        KnowledgeEntryRevision::factory()->create(['knowledge_entry_id' => $named->id, 'content' => 'x']);

        $historical = $this->entry('cennik', 'Aktualny cennik bez nazwisk.');
        KnowledgeEntryRevision::factory()->create([
            'knowledge_entry_id' => $historical->id,
            'content' => 'Stara wersja wspominala o Kowalska.',
        ]);

        KnowledgeLink::factory()->ghost($historical, 'anna-kowalska')->create();

        $before = $this->census();

        $this->assertSame(0, $this->purge(['Kowalska']));

        $output = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Entries matching CURRENT text (whole entry purged): 1', $output);
        $this->assertStringContainsString('Revisions matching HISTORY ONLY (revision deleted, entry kept): 1', $output);
        $this->assertStringContainsString('Ghost links whose target slug matches (deleted): 1', $output);
        $this->assertStringContainsString('Total matches: 3', $output);
        // The command must state its own limits in the artefact the operator archives.
        $this->assertStringContainsString('knowledge module only', $output);

        $this->assertSame($before, $this->census(), 'a dry run must not change a single row');
    }

    // ---- C-2: history granularity ---------------------------------------------

    public function test_a_match_only_in_history_deletes_that_revision_and_leaves_the_entry(): void
    {
        $entry = $this->entry('polityka-cen', 'Obecna tresc bez nazwisk.');

        $named = KnowledgeEntryRevision::factory()->create([
            'knowledge_entry_id' => $entry->id,
            'content' => 'Wersja z 2024: ustalone z Kowalska.',
        ]);
        $innocent = KnowledgeEntryRevision::factory()->create([
            'knowledge_entry_id' => $entry->id,
            'content' => 'Wersja z 2025 bez nazwisk.',
        ]);

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeEntryRevision::query()->find($named->id), 'the naming revision is gone');
        $this->assertNotNull(KnowledgeEntryRevision::query()->find($innocent->id), 'its siblings survive');
        $this->assertNotNull(KnowledgeEntry::query()->find($entry->id), 'the entry itself is untouched');
    }

    // ---- C-3: the ghost exception, and the rule it is an exception to ----------

    public function test_a_matching_ghost_is_deleted_while_the_degrade_rule_survives_elsewhere(): void
    {
        // Purged because its CONTENT names the subject — its own slug does not.
        $victim = $this->entry('raport-q3', 'Notatka ze spotkania z Kowalska.');
        $source = $this->entry('notatki', 'Zbior notatek.');

        // Inbound edge from a NON-matching source at a NON-matching slug: the standing rule applies.
        $degrades = KnowledgeLink::factory()->between($source, $victim)->create();

        // A ghost whose slug IS the person's name: the deliberate exception.
        $named = KnowledgeLink::factory()->ghost($source, 'anna-kowalska')->create();

        // A ghost about something else entirely: untouched.
        $unrelated = KnowledgeLink::factory()->ghost($source, 'cennik-2026')->create();

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($victim->id));

        $this->assertNull(KnowledgeLink::query()->find($named->id), 'a ghost slug that names the subject is deleted');
        $this->assertNotNull(KnowledgeLink::query()->find($unrelated->id), 'unrelated ghosts are left alone');

        $survivor = KnowledgeLink::query()->find($degrades->id);
        $this->assertNotNull($survivor, 'a backlink at a non-matching slug survives its target');
        $this->assertNull($survivor->to_entry_id, 'and it survives as a GHOST — the B1 rule, unchanged');
        $this->assertSame('raport-q3', $survivor->target_slug);
    }

    public function test_a_backlink_whose_slug_names_the_subject_is_cut_rather_than_degraded(): void
    {
        $victim = $this->entry('anna-kowalska', 'Profil osoby.');
        $source = $this->entry('notatki', 'Zbior notatek.');

        $link = KnowledgeLink::factory()->between($source, $victim)->create();

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(
            KnowledgeLink::query()->find($link->id),
            'degrading it would leave [[anna-kowalska]] rendered as a missing-entry chip on every linking page',
        );
    }

    // ---- C-4: the phrase never reaches the log --------------------------------

    public function test_the_phrase_never_reaches_the_application_log(): void
    {
        $entry = $this->entry('anna-kowalska', 'Profil osoby Kowalska.', [
            'metadata' => ['owner' => 'Kowalska'],
        ]);
        KnowledgeEntryChunk::factory()->forEntry($entry)->create();
        KnowledgeLink::factory()->ghost($entry, 'anna-kowalska')->create();

        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines) {
            $lines[] = $event->level . ' ' . $event->message . ' ' . json_encode($event->context);
        });

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNotEmpty($lines, 'the apply must be audited at all — an unlogged erasure is unauditable');

        foreach ($lines as $line) {
            $this->assertStringNotContainsStringIgnoringCase('Kowalska', $line, 'the phrase is the personal data; it must never be logged');
            // A slug reproduces the phrase exactly, which is why the log carries ids only.
            $this->assertStringNotContainsString('anna-kowalska', $line);
        }

        // ...while still being reconcilable against the operator's archived report.
        $audit = implode("\n", $lines);
        $this->assertStringContainsString($entry->id, $audit);
        $this->assertStringContainsString($this->workspace->id, $audit);
    }

    // ---- C-5: the breadth cap -------------------------------------------------

    public function test_a_phrase_broader_than_the_cap_is_refused_until_it_is_forced(): void
    {
        config(['knowledge.purge.max_entries' => 2]);

        foreach (['a', 'b', 'c'] as $slug) {
            $this->entry('notatka-' . $slug, 'Rozmowa z Kowalska.');
        }

        $before = $this->census();

        $this->assertSame(1, $this->purge(['Kowalska'], ['--apply' => true]), 'an over-broad phrase is refused');
        $this->assertStringContainsString('Phrase too broad', Artisan::output());
        $this->assertSame($before, $this->census(), 'and nothing was deleted');

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));
        $this->assertSame(0, KnowledgeEntry::withTrashed()->count(), '--force is the deliberate override');
    }

    // ---- the cascade ----------------------------------------------------------

    public function test_purging_an_entry_takes_its_revisions_and_its_embedded_chunks(): void
    {
        $entry = $this->entry('raport', 'Rozmowa z Kowalska.');
        KnowledgeEntryRevision::factory()->count(3)->create(['knowledge_entry_id' => $entry->id]);
        KnowledgeEntryChunk::factory()->forEntry($entry, 0)->create();
        KnowledgeEntryChunk::factory()->forEntry($entry, 1)->create();

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($entry->id));
        $this->assertSame(0, KnowledgeEntryRevision::query()->where('knowledge_entry_id', $entry->id)->count());
        $this->assertSame(
            0,
            KnowledgeEntryChunk::query()->where('knowledge_entry_id', $entry->id)->count(),
            'the vectors are derived from the erased text and must go with it',
        );
    }

    public function test_an_entry_in_the_trash_is_reached_too(): void
    {
        $trashed = $this->entry('stary-raport', 'Rozmowa z Kowalska.');
        $trashed->delete();

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(
            KnowledgeEntry::withTrashed()->find($trashed->id),
            '"we already deleted it" is not an answer an erasure request accepts',
        );
    }

    // ---- matching semantics ---------------------------------------------------

    public function test_the_base_option_narrows_the_scan_to_one_base(): void
    {
        $other = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $inScope = $this->entry('raport', 'Rozmowa z Kowalska.');
        $outOfScope = $this->entry('raport-obcy', 'Rozmowa z Kowalska.', ['knowledge_base_id' => $other->id]);

        $this->assertSame(0, $this->purge(['Kowalska'], [
            '--base' => $this->base->id,
            '--apply' => true,
            '--force' => true,
        ]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($inScope->id));
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($outOfScope->id));
    }

    public function test_an_unknown_base_is_refused_rather_than_silently_matching_nothing(): void
    {
        $this->entry('raport', 'Rozmowa z Kowalska.');

        $this->assertSame(1, $this->purge(['Kowalska'], [
            '--base' => '00000000-0000-4000-8000-000000000000',
            '--apply' => true,
            '--force' => true,
        ]));

        $this->assertSame(1, KnowledgeEntry::withTrashed()->count());
    }

    public function test_several_phrases_are_matched_as_or(): void
    {
        $nominative = $this->entry('a', 'Rozmowa z Kowalska.');
        $genitive = $this->entry('b', 'Wniosek Kowalskiej.');
        $unrelated = $this->entry('c', 'Cennik uslug.');

        $this->assertSame(0, $this->purge(['Kowalska', 'Kowalskiej'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($nominative->id));
        $this->assertNull(KnowledgeEntry::withTrashed()->find($genitive->id));
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($unrelated->id));
    }

    public function test_like_wildcards_inside_a_phrase_are_matched_literally(): void
    {
        $literal = $this->entry('a', 'Kod klienta: AB_12.');
        $wildcarded = $this->entry('b', 'Kod klienta: ABX12.');

        // Unescaped, `%AB_12%` would match ABX12 as well and destroy an unrelated entry.
        $this->assertSame(0, $this->purge(['AB_12'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($literal->id));
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($wildcarded->id), 'an underscore is not a wildcard');

        $percent = $this->entry('c', 'Rabat 30%X dla klienta.');
        $decoy = $this->entry('d', 'Rabat 30 procent dla klienta.');

        $this->assertSame(0, $this->purge(['30%X'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($percent->id));
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($decoy->id), 'a percent sign is not a wildcard');
    }

    public function test_a_blank_phrase_is_refused_rather_than_matching_everything(): void
    {
        $this->entry('raport', 'Rozmowa z Kowalska.');

        $this->assertSame(1, $this->purge(['   '], ['--apply' => true, '--force' => true]));
        $this->assertSame(1, KnowledgeEntry::withTrashed()->count());
    }

    public function test_metadata_values_are_searched(): void
    {
        $entry = $this->entry('klient', 'Notatka bez nazwiska w tresci.', [
            'metadata' => ['opiekun' => 'Anna Kowalska', 'region' => 'PL'],
        ]);

        $this->assertSame(0, $this->purge(['Kowalska']));
        $this->assertStringContainsString('metadata', Artisan::output());

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));
        $this->assertNull(KnowledgeEntry::withTrashed()->find($entry->id));
    }

    // ---- the machine report ---------------------------------------------------

    public function test_the_json_report_carries_every_section(): void
    {
        $purged = $this->entry('raport-q3', 'Rozmowa z Kowalska.');
        KnowledgeEntryChunk::factory()->forEntry($purged)->create();

        $kept = $this->entry('cennik', 'Aktualny cennik.');
        KnowledgeEntryRevision::factory()->create([
            'knowledge_entry_id' => $kept->id,
            'content' => 'Stara wersja: Kowalska.',
        ]);

        KnowledgeLink::factory()->ghost($kept, 'anna-kowalska')->create();

        $this->assertSame(0, $this->purge(['Kowalska'], ['--json' => true]));

        $report = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($report, 'with --json stdout must be exactly one JSON document');
        $this->assertSame('knowledge:purge-subject', $report['command']);
        $this->assertSame('knowledge', $report['scope']);
        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame($this->workspace->id, $report['workspace_id']);
        $this->assertSame(['Kowalska'], $report['phrases']);

        $this->assertSame(3, $report['totals']['matches']);
        $this->assertSame(1, $report['totals']['entries_purged']);
        $this->assertSame(1, $report['totals']['revisions_deleted']);
        $this->assertSame(1, $report['totals']['ghost_links_deleted']);
        $this->assertSame(1, $report['totals']['cascaded_chunks']);

        $this->assertSame($purged->id, $report['entries'][0]['id']);
        $this->assertSame(['content'], $report['entries'][0]['matched_in']);
        $this->assertSame('cennik', $report['revisions'][0]['entry_slug']);
        $this->assertSame('anna-kowalska', $report['ghost_links'][0]['target_slug']);

        // The future-proofing placeholder: present from day one so the gap is visible in every
        // archived report instead of being discovered after a request was certified as fulfilled.
        $this->assertArrayHasKey('generation_session_snapshots', $report);
        $this->assertSame(0, $report['generation_session_snapshots']);

        $this->assertSame(0, $this->purge(['Kowalska'], ['--json' => true, '--apply' => true, '--force' => true]));
        $this->assertSame('applied', json_decode(trim(Artisan::output()), true)['mode']);
    }

    /**
     * The TEXT report must list matched drafting sessions, because they count towards the number the
     * operator retypes to confirm an apply.
     *
     * That is the whole point of the typed confirmation: it forces a reading of the report. A number
     * that included rows the report never showed would certify deletions nobody was shown — the
     * confirmation would still feel deliberate while guaranteeing less than it appears to.
     */
    public function test_the_text_report_lists_matched_drafting_sessions(): void
    {
        $session = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'source_text' => 'Notatka ze spotkania z Kowalska o budzecie.',
        ]);

        $this->assertSame(0, $this->purge(['Kowalska']));

        $output = Artisan::output();

        $this->assertStringContainsString('Drafting sessions whose pasted material matches', $output);
        $this->assertStringContainsString((string) $session->id, $output);
        $this->assertStringContainsString('source_text', $output, 'the report says WHICH field carried the phrase');

        // ...and the count the operator would retype includes it.
        $this->assertStringContainsString('Total matches: 1', $output);

        // The matched TEXT itself is never printed: the report is archived, and the pasted material IS
        // the personal data the request is about.
        $this->assertStringNotContainsString('Notatka ze spotkania', $output);
    }

    /**
     * A RELATION'S OWN TEXT is a place a person's name lands that nothing else in this command reaches.
     *
     * The category looks unnecessary at first: purge the two entries and the cascade takes their
     * relations with them. True, and irrelevant to the case here — the name is written on the EDGE and
     * neither entry mentions it, so neither entry matches, so nothing is purged and the sentence
     * survives an erasure that reported itself complete.
     */
    public function test_a_relation_whose_own_text_names_the_subject_is_deleted(): void
    {
        $anna = $this->entry('anna', 'Wpis bez nazwiska w tresci.');
        $acme = $this->entry('acme', 'Firma, rowniez bez nazwiska.');

        $onDescription = KnowledgeRelation::factory()->between($anna, $acme)->create([
            'description' => 'Wprowadzona przez Kowalska w marcu.',
        ]);

        $onProperties = KnowledgeRelation::factory()
            ->between($acme, $anna)
            ->ofType(KnowledgeRelationType::WORKS_ON)
            ->create(['properties' => ['role' => 'asystentka Kowalska']]);

        $untouched = KnowledgeRelation::factory()
            ->between($anna, $acme)
            ->ofType(KnowledgeRelationType::USES)
            ->create(['description' => 'Zwykly opis bez nazwisk.']);

        $this->assertSame(0, $this->purge(['Kowalska']));

        $output = Artisan::output();
        $this->assertStringContainsString('Relations whose description or properties match (deleted): 2', $output);
        $this->assertStringContainsString('description', $output);
        $this->assertStringContainsString('properties', $output);
        // The matched text is never printed — the report is archived, and this IS the personal data.
        $this->assertStringNotContainsString('Wprowadzona przez', $output);

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeRelation::query()->find($onDescription->id));
        $this->assertNull(KnowledgeRelation::query()->find($onProperties->id));
        $this->assertNotNull(KnowledgeRelation::query()->find($untouched->id), 'unrelated relations are untouched');
        // The ENTRIES survive: neither of them named the subject.
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($anna->id));
    }

    /**
     * The FROZEN CONTEXT on a drafting session is a COPY of other entries' text, and since the
     * amendment fix it holds whole documents rather than 1500-character excerpts. Purging an entry
     * would otherwise leave a full copy of it — name included — sitting in a jsonb column nothing else
     * looks at.
     */
    public function test_a_session_whose_frozen_context_names_the_subject_is_purged(): void
    {
        $session = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            // The pasted material is CLEAN; only the retrieved context carries the name.
            'source_text' => 'Material bez nazwisk.',
            'retrieval_set' => [
                'items' => [[
                    'slug' => 'zwroty',
                    'title' => 'Zwroty',
                    'current_revision_id' => null,
                    'excerpt' => 'Procedure zatwierdzila Kowalska w zeszlym roku.',
                    'truncated' => false,
                ]],
                'omitted' => [],
            ],
        ]);

        $this->assertSame(0, $this->purge(['Kowalska']));

        $output = Artisan::output();
        $this->assertStringContainsString((string) $session->id, $output);
        $this->assertStringContainsString('retrieval_set', $output, 'the report says WHICH field carried the phrase');

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
    }

    /**
     * THE FROZEN RESOLUTION SET — the identity ledger the Wiki-Graph phase added.
     *
     * It is the worst-shaped surface in the module for an erasure request: it exists precisely to
     * record WHICH REAL PERSON a name in somebody's notes referred to, so it carries the name, the
     * surface forms it appeared as, and an excerpt of that person's entry — three copies of the thing
     * being erased, in one opaque JSON blob that no entry-level purge would ever reach.
     */
    public function test_a_session_whose_resolution_set_names_the_subject_is_purged(): void
    {
        $session = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'source_text' => 'Material bez nazwisk.',
            'resolution_set' => [
                'entities' => [[
                    'handle' => 'E1',
                    'id' => 'nieistotne',
                    'slug' => 'kowalska',
                    'title' => 'Anna Kowalska',
                    'mentions' => ['Kowalska'],
                    'content' => 'Kowalska prowadzi zespol zwrotow.',
                    'truncated' => false,
                    'relations' => [],
                ]],
                'ambiguous' => [],
                'unresolved' => [],
                'degraded' => [],
            ],
        ]);

        $this->assertSame(0, $this->purge(['Kowalska']));

        $output = Artisan::output();
        $this->assertStringContainsString((string) $session->id, $output);
        $this->assertStringContainsString('resolution_set', $output, 'the report says WHICH field carried the phrase');

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
    }

    /**
     * THE LAUNDERED PROPOSAL, which holds PROSE and not merely identifiers.
     *
     * `graph_ops.wiki_updates[].content` is a whole paragraph a model wrote about a person, and
     * `graph_updates[].description` is a sentence stating a relationship they are part of. Neither is
     * an entry, neither is a revision, and neither is reachable from any other row — a workspace could
     * purge every entry it owns and still be holding both. This is also the surface that grows: every
     * future field of the proposal lands in the same column, and the scan reads the column rather than
     * the fields, which is why it keeps working when the shape moves.
     */
    public function test_a_session_whose_graph_proposal_names_the_subject_is_purged(): void
    {
        $session = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'source_text' => 'Material bez nazwisk.',
            'resolution_set' => ['entities' => [], 'ambiguous' => [], 'unresolved' => [], 'degraded' => []],
            'graph_ops' => [
                'entities' => [['ref' => 'N1', 'title' => 'Kwadratura', 'slug' => null, 'entry_type' => 'organization', 'aliases' => []]],
                // PROSE about the subject, proposed and never accepted.
                'wiki_updates' => [['entity' => 'N1', 'op' => 'create', 'section' => null, 'content' => 'Firme zalozyla Kowalska w 2021.']],
                'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'member_of', 'description' => 'Kowalska prowadzi ten zespol.', 'properties' => [], 'valid_from' => null, 'valid_to' => null, 'replaces' => null]],
                'unresolved' => [],
                'rejected' => [],
                'warnings' => [],
            ],
        ]);

        $this->assertSame(0, $this->purge(['Kowalska']));

        $output = Artisan::output();
        $this->assertStringContainsString((string) $session->id, $output);
        $this->assertStringContainsString('graph_ops', $output);
        // THE PHRASE ITSELF IS NOT PRINTED. The report names the row and the field; a report that
        // quoted the matching text would recreate the exposure it exists to end.
        $this->assertStringNotContainsString('Firme zalozyla', $output);

        $json = $this->jsonReport(['Kowalska']);

        $this->assertSame(1, $json['totals']['draft_sessions_purged']);
        $this->assertSame([(string) $session->id], array_column($json['draft_sessions'], 'id'));
        $this->assertContains('graph_ops', $json['draft_sessions'][0]['matched_in']);

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
    }

    /**
     * A RELATION'S OWN TEXT, reported in the JSON totals as its own category.
     *
     * The deletion itself is covered elsewhere; what is pinned here is that an operator reading the
     * machine-readable report can SEE it. An erasure that happened but was not reported is
     * indistinguishable, in an audit, from one that did not happen.
     */
    public function test_the_json_report_counts_deleted_relations_as_their_own_category(): void
    {
        $from = $this->entry('zespol', 'Tresc bez nazwisk.');
        $to = $this->entry('firma', 'Inna tresc bez nazwisk.');

        KnowledgeRelation::factory()->between($from, $to)->create([
            'relation_type' => KnowledgeRelationType::PART_OF,
            'description' => 'Ustalone przez Kowalska na spotkaniu.',
        ]);

        $json = $this->jsonReport(['Kowalska']);

        $this->assertSame(1, $json['totals']['relations_deleted']);
        $this->assertSame(0, $json['totals']['entries_purged'], 'the entries at either end say nothing');
        $this->assertContains('description', $json['relations'][0]['matched_in']);
    }

    /**
     * THE RUN NOTES ARE SCANNED.
     *
     * `knowledge_draft_sessions.notes` is server-written commentary about what happened to a run, and
     * several of its codes carry an entry TITLE or SLUG — which for a `person` entry is somebody's
     * name. A session matched only through that column was invisible to the scan, so an erasure request
     * could complete and report SUCCESS while the name was still in the database.
     *
     * INVERTED from the G8 pin, which recorded the gap rather than endorsing it. Same granularity as
     * `source_text` and `prompt_history`: a hit abandons the whole session, because a session is a
     * working record and there is no field-level surgery to perform on a note.
     */
    public function test_a_session_matched_only_through_its_run_notes_is_purged(): void
    {
        $session = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'source_text' => 'Material bez nazwisk.',
        ]);

        $session->forceFill(['notes' => [
            ['code' => 'amend_too_long', 'title' => 'Anna Kowalska', 'slug' => 'kowalska'],
        ]])->save();

        $json = $this->jsonReport(['Kowalska']);

        $this->assertSame(1, $json['totals']['draft_sessions_purged']);
        $this->assertContains('notes', $json['draft_sessions'][0]['matched_in'], 'the report names WHICH field carried it');

        // ...and the row is gone after an apply.
        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));
        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
    }

    /** The phrase must not reach the log through any of the NEW surfaces either. */
    public function test_the_phrase_never_reaches_the_log_from_a_graph_proposal(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message . ' ' . json_encode($event->context);
        });

        KnowledgeDraftSession::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'source_text' => 'Material bez nazwisk.',
            'graph_ops' => [
                'entities' => [],
                'wiki_updates' => [['entity' => 'N1', 'op' => 'create', 'section' => null, 'content' => 'Firme zalozyla Kowalska.']],
                'graph_updates' => [],
                'unresolved' => [],
                'rejected' => [],
                'warnings' => [],
            ],
        ]);

        $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]);

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('Kowalska', $line);
        }
    }

    // ---- the confirmation gate ------------------------------------------------

    public function test_applying_interactively_requires_the_match_count_to_be_retyped(): void
    {
        $entry = $this->entry('raport', 'Rozmowa z Kowalska.');

        $this->artisan('knowledge:purge-subject', [
            'workspace' => $this->workspace->id,
            'phrase' => ['Kowalska'],
            '--apply' => true,
        ])->expectsQuestion('Type 1 to confirm erasing 1 match(es). This cannot be undone', '2')
            ->assertFailed();

        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($entry->id), 'a wrong answer deletes nothing');

        $this->artisan('knowledge:purge-subject', [
            'workspace' => $this->workspace->id,
            'phrase' => ['Kowalska'],
            '--apply' => true,
        ])->expectsQuestion('Type 1 to confirm erasing 1 match(es). This cannot be undone', '1')
            ->assertSuccessful();

        $this->assertNull(KnowledgeEntry::withTrashed()->find($entry->id));
    }

    // ---- B7: the scan → apply window ------------------------------------------

    /**
     * THE IDS FROM THE DRY RUN ARE AUTHORITATIVE — deliberately, and this pins both halves of it.
     *
     * {@see KnowledgeSubjectPurgeService::apply()} acts on the ids the scan produced instead of
     * re-querying, so an entry edited between the two is destroyed on the strength of the text the
     * operator READ, not the text that is there when the delete runs. The alternative — re-scanning at
     * apply time — sounds safer and is worse in both directions at once: the operator would be asked
     * to confirm a count for one set of rows and would then destroy a different set, and an erasure
     * request could be defeated by editing during the confirmation prompt.
     *
     * The consequence being accepted is stated plainly here: text written AFTER the scan goes with the
     * entry. That is the correct trade for an erasure request (the row was already condemned) but it
     * is not obvious, and nobody should discover it from a support ticket.
     */
    public function test_an_entry_edited_between_the_scan_and_the_apply_is_still_purged(): void
    {
        $purge = app(KnowledgeSubjectPurgeService::class);
        $phrases = SubjectPhrases::fromInput(['Kowalska']);

        $entry = $this->entry('raport', 'Rozmowa z Kowalska.');

        $report = $purge->scan((string) $this->workspace->id, $phrases);
        $this->assertCount(1, $report->entries);

        // The window: somebody edits the name out — or writes something else entirely — after the
        // operator read the report and before they confirmed it.
        $entry->forceFill(['content' => 'Notatka o czyms zupelnie innym.'])->save();

        $purge->apply($report, $phrases);

        $this->assertNull(
            KnowledgeEntry::withTrashed()->find($entry->id),
            'the report the operator confirmed is what gets destroyed, and it named this id',
        );
    }

    /** The same rule in the other direction: what the scan did not see, the apply does not touch. */
    public function test_an_entry_that_starts_matching_after_the_scan_is_left_alone(): void
    {
        $purge = app(KnowledgeSubjectPurgeService::class);
        $phrases = SubjectPhrases::fromInput(['Kowalska']);

        $matched = $this->entry('raport', 'Rozmowa z Kowalska.');
        $latecomer = $this->entry('notatka', 'Nic tu nie ma.');

        $report = $purge->scan((string) $this->workspace->id, $phrases);
        $this->assertCount(1, $report->entries);

        $latecomer->forceFill(['content' => 'Teraz jednak wspomina Kowalska.'])->save();

        $purge->apply($report, $phrases);

        $this->assertNull(KnowledgeEntry::withTrashed()->find($matched->id));
        $this->assertNotNull(
            KnowledgeEntry::withTrashed()->find($latecomer->id),
            'an apply must destroy exactly the reviewed set — never more',
        );
    }

    // ---- B7: --json stays machine-readable on every refusal --------------------

    /**
     * `--json` promises stdout carries EXACTLY one JSON document, and the promise is worth least on
     * the happy path: a caller piping this into a compliance archive only ever needs to PARSE the
     * output when something went wrong. A stray prose line on a refusal — the error message, the
     * confirmation prompt — turns a machine-readable refusal into a parse failure at precisely the
     * moment the operator needs to know why nothing was deleted.
     *
     * All three refusals are covered together because they leave the command by three different
     * doors: {@see PurgeKnowledgeSubjectCommand::refuse()}, the breadth cap, and the confirmation
     * gate.
     */
    public function test_json_stays_a_single_document_when_apply_is_refused(): void
    {
        $entry = $this->entry('raport', 'Rozmowa z Kowalska.');

        // 1. An unknown base, with --apply --force: the strongest flags still cannot make it delete,
        //    and the refusal is a JSON error code rather than a sentence.
        $this->assertSame(1, $this->purge(['Kowalska'], [
            '--base' => '00000000-0000-4000-8000-000000000000',
            '--apply' => true,
            '--force' => true,
            '--json' => true,
        ]));

        $refusal = $this->decodeSingleJson(Artisan::output());
        $this->assertSame('base_not_found', $refusal['error']);
        $this->assertSame('knowledge', $refusal['scope']);
        $this->assertArrayNotHasKey('totals', $refusal, 'a refusal is not a report');

        // 2. A blank phrase, same shape.
        $this->assertSame(1, $this->purge(['   '], ['--apply' => true, '--force' => true, '--json' => true]));
        $this->assertSame('no_usable_phrase', $this->decodeSingleJson(Artisan::output())['error']);

        // 3. Over-broad, WITHOUT --force: the full report is still emitted (the operator has to see
        //    what the phrase dragged in) and the prose warning stays off stdout.
        $second = $this->entry('notatka', 'Druga wzmianka o Kowalska.');
        config(['knowledge.purge.max_entries' => 1]);

        $this->assertSame(1, $this->purge(['Kowalska'], ['--apply' => true, '--json' => true]));

        $tooBroad = $this->decodeSingleJson(Artisan::output());
        $this->assertSame('too_broad', $tooBroad['refused']);
        $this->assertSame(2, $tooBroad['totals']['entries_purged'], 'the report is printed so the phrase can be narrowed');
        $this->assertStringNotContainsString('Phrase too broad', Artisan::output());

        config(['knowledge.purge.max_entries' => 100]);

        // 4. --apply WITHOUT --force is non-interactive here, so the confirmation cannot be asked for
        //    and must not be auto-granted. The refusal is reported IN the document.
        $this->assertSame(1, $this->purge(['Kowalska'], ['--apply' => true, '--json' => true]));

        $unconfirmed = $this->decodeSingleJson(Artisan::output());
        $this->assertSame('not_confirmed', $unconfirmed['refused']);
        $this->assertSame('dry-run', $unconfirmed['mode'], 'a refused apply must never report itself as applied');

        // Every one of the four refused, and both entries are still there.
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($entry->id));
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($second->id));
    }

    // ---- B7: a base that is itself in the trash --------------------------------

    /**
     * A phrase whose ONLY matches are entries that fell into the trash with their base, reached
     * through `--base`.
     *
     * Three separate mechanisms have to hold for this to work, and each of them fails silently on its
     * own: the base lookup reads through the soft-delete filter (a trashed base id must not read as
     * "not found", which the command refuses outright); the entry scan reads through it too; and the
     * `--base` narrowing has to match entries whose own `deleted_at` is set. If any one of them
     * filtered, the operator would get "0 matches" for a base full of the person's data — the single
     * most dangerous wrong answer this command can give, because it is indistinguishable from success.
     */
    public function test_a_trashed_base_is_still_reachable_by_its_id(): void
    {
        $doomed = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $inside = $this->entry('raport-q3', 'Ustalenia z Kowalska.', ['knowledge_base_id' => $doomed->id]);
        $elsewhere = $this->entry('cennik', 'Aktualny cennik bez nazwisk.');

        app(KnowledgeBaseService::class)->delete($doomed);

        $this->assertTrue($doomed->fresh()->trashed());
        $this->assertTrue(
            KnowledgeEntry::withTrashed()->findOrFail($inside->id)->trashed(),
            'the fixture must really be an entry that fell with its base',
        );

        // The dry run finds it — "nothing matched" here would be a certified-complete erasure that
        // erased nothing.
        $this->assertSame(0, $this->purge(['Kowalska'], ['--base' => $doomed->id]));
        $output = Artisan::output();
        $this->assertStringContainsString('Entries matching CURRENT text (whole entry purged): 1', $output);
        $this->assertStringContainsString('(in trash)', $output, 'the report must say the row is already trashed');

        $this->assertSame(0, $this->purge(['Kowalska'], [
            '--base' => $doomed->id,
            '--apply' => true,
            '--force' => true,
        ]));

        $this->assertNull(KnowledgeEntry::withTrashed()->find($inside->id));
        $this->assertNotNull(
            KnowledgeEntry::withTrashed()->find($elsewhere->id),
            'and --base still narrowed the blast radius to that one base',
        );
    }

    /** @return array<string, mixed> */
    private function decodeSingleJson(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'with --json stdout must parse as exactly one JSON document, got: ' . $output);

        return $decoded;
    }

    // ---- links of other kinds -------------------------------------------------

    public function test_a_matching_ghost_is_deleted_whatever_drew_it(): void
    {
        $source = $this->entry('notatki', 'Zbior notatek.');

        $manual = KnowledgeLink::factory()
            ->ghost($source, 'anna-kowalska')
            ->source(KnowledgeLinkSource::MANUAL)
            ->create();

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNull(KnowledgeLink::query()->find($manual->id));
    }

    // ---- surfaces the Wiki-Graph stage added ----------------------------------

    /**
     * AN ALIAS IS A NAME. The Wiki-Graph stage added the column and did not extend the scan.
     *
     * `aliases` exists to hold the OTHER names for a thing, which on a `person` entry means a maiden
     * name, a nickname, a form with initials — the exact strings an erasure request is about. An entry
     * whose title and body had already been cleaned matched nothing, so the request completed
     * successfully with the name still in the database and the mention scanner still drawing edges from
     * it.
     */
    public function test_an_entry_whose_alias_names_the_subject_is_purged(): void
    {
        $entry = $this->entry('a-k', 'Tresc bez nazwiska.', [
            'title' => 'A. K.',
            'aliases' => ['Anna Kowalska', 'AK'],
        ]);

        $json = $this->jsonReport(['Kowalska']);

        $this->assertSame(1, $json['totals']['entries_purged']);
        $this->assertContains('aliases', $json['entries'][0]['matched_in']);

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));
        $this->assertNull(KnowledgeEntry::withTrashed()->find($entry->id));
    }

    /**
     * A SLUG DOES NOT FOLLOW A RENAME, which is exactly why it has to be scanned.
     *
     * Renaming the entry to "A. K." is the obvious first move on an erasure request, and it leaves the
     * address `anna-kowalska` behind for good — a slug is what links point at, so this module will
     * never move one. The phrase is matched in its slugified form too, because "Anna Kowalska" with a
     * space cannot find it.
     */
    public function test_an_entry_whose_slug_names_the_subject_is_purged(): void
    {
        $entry = $this->entry('anna-kowalska', 'Tresc bez nazwiska.', ['title' => 'A. K.']);

        $json = $this->jsonReport(['Anna Kowalska']);

        $this->assertSame(1, $json['totals']['entries_purged']);
        $this->assertContains('slug', $json['entries'][0]['matched_in']);

        $this->assertSame(0, $this->purge(['Anna Kowalska'], ['--apply' => true, '--force' => true]));
        $this->assertNull(KnowledgeEntry::withTrashed()->find($entry->id));
    }

    /**
     * THE AUDIT TRAIL OF A RELATION, once the relation itself has been cleaned up.
     *
     * The entry-versus-revision asymmetry one table further out, and reached by the RESPONSIBLE act:
     * somebody edits the description to take the name out, and `auditSnapshot()` preserves it verbatim
     * in the `before` half of the event that recorded the removal. The relation is clean, both entries
     * are clean, and the name is still there — reachable by nothing else in this command, because the
     * event table deliberately has no foreign key to the relation and no API that rewrites a line.
     *
     * Deleted at the granularity of the EVENT: the relation and the rest of its history survive.
     */
    public function test_a_relation_audit_line_that_outlived_the_correction_is_deleted(): void
    {
        $anna = $this->entry('osoba', 'Wpis bez nazwiska.');
        $acme = $this->entry('firma', 'Firma bez nazwiska.');

        $relation = KnowledgeRelation::factory()->between($anna, $acme)->create([
            'description' => 'Opis juz poprawiony, bez nazwiska.',
        ]);

        // The line the correction left behind: `before` still holds what was removed.
        $dirty = KnowledgeRelationEvent::query()->create([
            'relation_id' => $relation->id,
            'knowledge_base_id' => $this->base->id,
            'op' => 'update',
            'before' => ['description' => 'Wprowadzona przez Anne Kowalska.'],
            'after' => ['description' => 'Opis juz poprawiony, bez nazwiska.'],
            'created_at' => now(),
        ]);

        $clean = KnowledgeRelationEvent::query()->create([
            'relation_id' => $relation->id,
            'knowledge_base_id' => $this->base->id,
            'op' => 'create',
            'before' => null,
            'after' => ['description' => 'Opis juz poprawiony, bez nazwiska.'],
            'created_at' => now(),
        ]);

        $json = $this->jsonReport(['Kowalska']);

        $this->assertSame(0, $json['totals']['relations_deleted'], 'the relation itself is clean');
        $this->assertSame(1, $json['totals']['relation_events_deleted']);
        $this->assertSame((string) $dirty->id, $json['relation_events'][0]['id']);
        $this->assertSame(['before'], $json['relation_events'][0]['matched_in']);

        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        // The snapshot text is never printed — the report is archived, and this IS the personal data.
        $this->assertStringNotContainsString('Wprowadzona przez', Artisan::output());

        $this->assertNull(KnowledgeRelationEvent::query()->find($dirty->id));
        $this->assertNotNull(KnowledgeRelationEvent::query()->find($clean->id), 'the rest of the trail survives');
        $this->assertNotNull(KnowledgeRelation::query()->find($relation->id), 'and so does the relation');
    }

    /**
     * A CHARTER IS REPORTED AND NEVER DELETED — the one report-only category.
     *
     * The charter is prepended to the compiled knowledge block on every generation against its base, so
     * a person named in one is being sent to the provider continuously: it is the most ACTIVE copy of
     * their data in the module, and it was invisible to the scan. An operator could read "0 records"
     * and certify an erasure that had not happened.
     *
     * It is still not deleted, because the charter belongs to the BASE — deleting the base would
     * destroy every entry in it, and blanking the policy would silently change every future generation.
     * The command names the base, says a human has to edit it, and stops. Both halves are asserted
     * here, because "report" and "delete" are the two decisions that were conflated the first time.
     */
    public function test_a_charter_naming_the_subject_is_reported_but_never_deleted(): void
    {
        $this->base->forceFill(['charter' => 'Baza o zwrotach. Redaktorem jest Anna Kowalska.'])->save();

        $survivor = $this->entry('zwroty', 'Tresc bez nazwiska.');

        $json = $this->jsonReport(['Kowalska']);

        // FLAGGED, not deleted — and the counter is named so nobody can read it as done.
        $this->assertSame(1, $json['totals']['charters_flagged']);
        $this->assertArrayNotHasKey('charters_deleted', $json['totals']);
        $this->assertSame((string) $this->base->id, $json['charters'][0]['knowledge_base_id']);
        $this->assertSame('manual_edit', $json['charters'][0]['action_required']);
        $this->assertFalse($json['charters'][0]['deleted']);

        // It is NOT part of the number that gates and confirms an apply.
        $this->assertSame(0, $json['totals']['matches'], 'a report-only finding cannot refuse an apply');

        // The prose report says what the operator has to do, and never gives an all-clear.
        $this->purge(['Kowalska']);
        $output = Artisan::output();

        $this->assertStringContainsString('MANUAL EDIT REQUIRED', $output);
        $this->assertStringNotContainsString('Nothing matched', $output);

        // --apply touches neither the base nor its entries.
        $this->assertSame(0, $this->purge(['Kowalska'], ['--apply' => true, '--force' => true]));

        $this->assertNotNull(KnowledgeBase::query()->find($this->base->id), 'the base survives');
        $this->assertSame(
            'Baza o zwrotach. Redaktorem jest Anna Kowalska.',
            (string) KnowledgeBase::query()->findOrFail($this->base->id)->charter,
            'and so does the charter, verbatim — this command does not edit it',
        );
        $this->assertNotNull(KnowledgeEntry::query()->find($survivor->id), 'and its entries are untouched');
    }

    /**
     * THE BREADTH CAP COUNTS EVERYTHING THE PHRASE REACHED, not entries alone.
     *
     * A phrase matching NO entry and a pile of draft sessions used to sail straight past the guard —
     * and abandoning a session destroys every draft under it, so the loosest phrase in the system could
     * delete the most work with nothing in its way. The cap has to see the same list the operator is
     * being asked to certify.
     */
    public function test_the_breadth_cap_counts_sessions_and_not_only_entries(): void
    {
        config()->set('knowledge.purge.max_entries', 2);

        foreach (range(1, 3) as $index) {
            KnowledgeDraftSession::factory()->create([
                'workspace_id' => $this->workspace->id,
                'knowledge_base_id' => $this->base->id,
                'source_text' => "Material {$index}: notatka, autor Anna Kowalska.",
            ]);
        }

        $this->assertSame(0, KnowledgeEntry::query()->count(), 'not one entry matches');

        $exit = $this->purge(['Kowalska'], ['--apply' => true]);

        $this->assertSame(1, $exit, 'refused as too broad');
        $this->assertSame(3, KnowledgeDraftSession::query()->count(), 'and nothing was deleted');
    }
}
