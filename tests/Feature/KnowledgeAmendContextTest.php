<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\DraftRunNotes;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G1 — WHAT THE COMPOSER IS ALLOWED TO REWRITE.
 *
 * The defect this file exists for is worth stating exactly, because everything below is a consequence
 * of it. The composer is instructed that an amendment must carry the entry's COMPLETE new text. The
 * retrieval freeze was handing it a 1500-character EXCERPT. Shown a quarter of a 6000-character entry,
 * a cooperative model returns a "complete" body reconstructed from the quarter it saw — and accepting
 * that proposal deletes three quarters of the document behind a diff that looks like an ordinary
 * rewrite. Nothing anywhere said so, and no test noticed, because every fixture in the suite happened
 * to be shorter than the excerpt cap.
 *
 * The fix has two halves and BOTH are pinned here:
 *
 *   1. An amendment candidate is shown the WHOLE entry, up to `drafting.amend_full_chars`. Past that
 *      it is shown truncated and marked as such.
 *   2. A truncated candidate may only be APPENDED to. That is enforced when the answer is laundered,
 *      not merely asked for in the prompt — the model's cooperation is not part of the safety
 *      argument — and the addition is composed against the LIVE entry, so text the model never read
 *      cannot be lost whatever it returns.
 *
 * The end-to-end pin is {@see test_accepting_an_append_keeps_the_part_the_composer_never_saw}: it
 * fails loudly under the old behaviour, which is the property that makes the rest of this file worth
 * keeping.
 */
class KnowledgeAmendContextTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    /** The sentence at the very END of a long entry — the part an excerpt can never show. */
    private const TAIL = 'OSTATNIE ZDANIE DOKUMENTU, ktorego model nigdy nie zobaczyl.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'language' => 'pl',
        ]);

        // The RETRIEVAL branch, stated rather than inherited — see KnowledgeShadowDraftTest's setUp
        // for what depending on the ambient `.env` cost.
        config()->set('knowledge.graph_extraction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------

    private function fakeComposer(array $entries): void
    {
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE));
    }

    /** A real, indexed entry whose stored passage is an exact vector match for `$matches`. */
    private function retrievableEntry(string $title, string $slug, string $content, string $matches): KnowledgeEntry
    {
        $id = (string) $this->makeEntry(base: $this->base, title: $title, content: $content)->id;

        $entry = KnowledgeEntry::query()->findOrFail($id);
        $entry->forceFill(['slug' => $slug])->save();

        // EVERY passage of the entry is aligned with the source vector, not just the first: a 20 000
        // character entry is many chunks, and leaving the rest unaligned would make the match depend on
        // which chunk the ranker happened to return.
        $chunks = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->id)
            ->orderBy('ordinal')
            ->get();

        foreach ($chunks as $chunk) {
            ChunkVector::write(
                (string) $chunk->id,
                FakeKnowledgeEmbedder::vectorFor($matches, (int) config('knowledge.embedding.dimensions')),
                (string) config('knowledge.embedding.model'),
                now(),
            );
        }

        return $entry->refresh();
    }

    /** A body of roughly $chars characters whose LAST sentence is {@see TAIL}. */
    private function longBody(int $chars): string
    {
        $filler = str_repeat('Polityka zwrotow towaru w sklepie internetowym. ', (int) ceil($chars / 47));

        return mb_substr($filler, 0, max(1, $chars - mb_strlen(self::TAIL) - 1)) . ' ' . self::TAIL;
    }

    private function start(string $sourceText): KnowledgeDraftSession
    {
        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => $sourceText,
        ])->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id);
    }

    // ---- how much of an entry the composer is shown ------------------------------------

    /**
     * An entry that fits `amend_full_chars` is shown WHOLE — including the last sentence, which is the
     * whole point. Under the old excerpt cap this entry arrived at the model with two thirds missing.
     */
    public function test_an_amendment_candidate_is_shown_in_full(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $content = $this->longBody(5000);
        $this->retrievableEntry('Zwroty', 'zwroty', $content, $source);

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start($source);
        $set = $session->refresh()->retrievalSet();

        $this->assertCount(1, $set);
        $this->assertSame($content, $set[0]['excerpt']);
        $this->assertFalse($set[0]['truncated']);

        KnowledgeDraftAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, self::TAIL)
            && str_contains($prompt->prompt, '— COMPLETE'));
    }

    /** Past the cap the entry is cut, and the mark that says so is in the prompt. */
    public function test_an_over_long_candidate_is_shown_in_part_and_marked(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(20000), $source);

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start($source);
        $set = $session->refresh()->retrievalSet();

        $this->assertCount(1, $set);
        $this->assertTrue($set[0]['truncated']);
        $this->assertSame((int) config('knowledge.drafting.amend_full_chars'), mb_strlen($set[0]['excerpt']));

        KnowledgeDraftAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '— SHOWN IN PART (append only)')
            // The withheld tail must not have leaked into the prompt by some other route.
            && !str_contains($prompt->prompt, self::TAIL));
    }

    // ---- the enforcement ---------------------------------------------------------------

    /**
     * THE FIX, stated as one assertion: a model that ignores the append instruction and returns a
     * "complete" rewrite of an entry it saw a fraction of does not get to delete the rest.
     */
    public function test_a_rewrite_of_a_truncated_entry_is_degraded_to_an_append(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(20000), $source);

        // The disobedient answer: mode "rewrite", content holding a whole-document reconstruction.
        $this->fakeComposer([
            [
                'action' => 'update',
                'mode' => 'rewrite',
                'targets_slug' => 'zwroty',
                'title' => 'Zwroty',
                'content' => 'Zwroty przyjmujemy w 30 dni.',
                'metadata' => [],
            ],
        ]);

        $session = $this->start($source);
        $drafts = $session->drafts()->get();

        $this->assertCount(1, $drafts);
        $this->assertTrue($drafts[0]->isShadow());

        $this->assertTrue($drafts[0]->isAppendShadow());

        // The shadow stores the ADDITION, not a composed body. That is what lets the result be
        // assembled from the LIVE entry at acceptance, so a concurrent human edit survives.
        $this->assertSame('Zwroty przyjmujemy w 30 dni.', (string) $drafts[0]->content);

        // What the REVIEWER sees is the result: everything that was there, plus the model's sentence.
        $preview = $drafts[0]->amendedBody();
        $this->assertStringStartsWith(rtrim((string) $target->content), $preview);
        $this->assertStringContainsString(self::TAIL, $preview);
        $this->assertStringEndsWith('Zwroty przyjmujemy w 30 dni.', $preview);

        // ...and the reviewer is told, because otherwise an addition where a replacement was asked for
        // is an unexplained surprise.
        $this->assertSame(
            [['code' => DraftRunNotes::AMEND_APPEND_ONLY, 'slug' => 'zwroty']],
            $session->refresh()->runNotes(),
        );
    }

    /** The same fact from the outside: accepting the proposal must not lose the withheld tail. */
    public function test_accepting_an_append_keeps_the_part_the_composer_never_saw(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $target = $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(20000), $source);

        $this->fakeComposer([
            [
                'action' => 'update',
                'mode' => 'rewrite',
                'targets_slug' => 'zwroty',
                'title' => 'Zwroty',
                'content' => 'Zwroty przyjmujemy w 30 dni.',
                'metadata' => [],
            ],
        ]);

        $session = $this->start($source);
        $shadow = $session->drafts()->firstOrFail();

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [$shadow->id],
            'status' => 'approved',
        ])->assertOk();

        $target->refresh();

        $this->assertStringContainsString(self::TAIL, (string) $target->content);
        $this->assertStringContainsString('Zwroty przyjmujemy w 30 dni.', (string) $target->content);
    }

    /** An entry shown whole keeps the old contract: the model's text REPLACES the body. */
    public function test_a_rewrite_of_a_complete_entry_still_replaces_the_body(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(5000), $source);

        $this->fakeComposer([
            [
                'action' => 'update',
                'targets_slug' => 'zwroty',
                'title' => 'Zwroty',
                'content' => 'Zwroty przyjmujemy w 30 dni.',
                'metadata' => [],
            ],
        ]);

        $session = $this->start($source);
        $shadow = $session->drafts()->firstOrFail();

        $this->assertSame('Zwroty przyjmujemy w 30 dni.', (string) $shadow->content);
        $this->assertSame([], $session->refresh()->runNotes());
    }

    /**
     * An append that would burst `entry_max_chars` is DROPPED, not trimmed. Trimming the addition ships
     * half a sentence; trimming the body deletes the document. The entry needs splitting, which is a
     * person's decision — so the proposal goes and the reviewer is told why.
     */
    public function test_an_append_that_would_burst_the_entry_cap_is_dropped(): void
    {
        config()->set('knowledge.entry_max_chars', 20500);

        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(20000), $source);

        $this->fakeComposer([
            [
                'action' => 'update',
                'targets_slug' => 'zwroty',
                'title' => 'Zwroty',
                'content' => str_repeat('Nowy akapit o zwrotach. ', 40),
                'metadata' => [],
            ],
            // A survivor, so the run does not simply fail empty and hide the note.
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start($source);
        $drafts = $session->drafts()->get();

        $this->assertCount(1, $drafts);
        $this->assertFalse($drafts[0]->isShadow());

        $codes = array_column($session->refresh()->runNotes(), 'code');
        $this->assertContains(DraftRunNotes::AMEND_TOO_LONG, $codes);
    }

    // ---- the context budget -------------------------------------------------------------

    /**
     * What does not fit is NAMED. A composer that believes the base says nothing about a subject writes
     * a second entry about it — which is the duplicate the whole retrieval layer exists to prevent, so
     * dropping an entry in silence is the one thing the ceiling must not do.
     */
    public function test_an_entry_that_does_not_fit_the_ceiling_is_named_rather_than_dropped(): void
    {
        config()->set('knowledge.drafting.retrieval_total_chars', 50);

        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $this->retrievableEntry('Zwroty towaru', 'zwroty', $this->longBody(5000), $source);

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start($source)->refresh();

        $this->assertSame([], $session->retrievalSet());
        $this->assertSame(['Zwroty towaru'], $session->retrievalOmitted());

        KnowledgeDraftAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'THE BASE ALSO COVERS THESE')
            && str_contains($prompt->prompt, 'Zwroty towaru'));
    }

    /** The block the ceiling governs actually stays under it. */
    public function test_the_frozen_context_stays_within_the_ceiling(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(20000), $source);

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'x', 'title' => 'X', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start($source)->refresh();

        $used = array_sum(array_map(
            static fn (array $item): int => mb_strlen($item['excerpt']),
            $session->retrievalSet(),
        ));

        $this->assertLessThanOrEqual((int) config('knowledge.drafting.retrieval_total_chars'), $used);
    }

    // ---- the refinement round trip -------------------------------------------------------

    /**
     * A refinement must not replay the composed body of an append proposal.
     *
     * It would hand back through the side door exactly what the truncation withheld at the front —
     * and worse, the stored body is assembled from the LIVE entry, so replaying it would show the
     * composer text it was deliberately never given. The addition alone is what it revises.
     */
    public function test_a_refinement_shows_only_the_proposed_addition(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni zamiast 14.';
        $this->retrievableEntry('Zwroty', 'zwroty', $this->longBody(20000), $source);

        $this->fakeComposer([
            [
                'action' => 'update',
                'targets_slug' => 'zwroty',
                'title' => 'Zwroty',
                'content' => 'Zwroty przyjmujemy w 30 dni.',
                'metadata' => [],
            ],
        ]);

        $session = $this->start($source);

        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", [
            'instruction' => 'Napisz krocej.',
        ])->assertOk();

        KnowledgeDraftAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'your proposed ADDITION to [zwroty]')
            && str_contains($prompt->prompt, 'Zwroty przyjmujemy w 30 dni.')
            && !str_contains($prompt->prompt, self::TAIL));
    }
}
