<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Exceptions\KnowledgeSeedIsAliasException;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeDraftSessionService;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * AN INFLECTED WIKILINK TARGET RESOLVES TO THE ENTRY IT MEANS.
 *
 * ------------------------------------------------------------------------------------------------
 * THE CASE, AND WHY EVERY LAYER WAS INNOCENT
 *
 * The base holds "Nowe Tokio" — slug `nowe-tokio`, alias "Nowego Tokio". A composed entry wrote
 * `[[nowego-tokio]]`, which is the inflected form, and the base then said two different things about
 * one sentence: the MENTION scanner matched the alias and drew a live edge, while the WIKILINK matched
 * no slug and became a ghost. The same words, one green link and one red, with nothing on any screen
 * to explain the difference.
 *
 * It got worse downstream. The reader clicked the red link to write the missing entry; the composer
 * started with `seed_slug=nowego-tokio`; resolution matched the alias to the existing entry —
 * correctly — the model declined to write a duplicate — correctly — and the seed check then failed the
 * run with `seed_missed`. A red link that could not be filled, and a paid call that never could have
 * succeeded.
 *
 * ------------------------------------------------------------------------------------------------
 * THE FIX IS AT THE WRITE, NOT AT THE READ
 *
 * The slug stays the one and only address a link resolves by (ADR-0048): nothing looks an alias up
 * when rendering. What changed is that a target which matches NO slug but IS an entry's alias is
 * corrected to that entry's canonical slug BEFORE the edge is stored. Where the two could disagree —
 * one entry's alias slugifying to another entry's address — the slug wins, always.
 */
class KnowledgeInflectedLinkTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $workspace->id);
        app(TenantContext::class)->set($workspace);

        $this->app->instance(KnowledgeEmbedder::class, new FakeKnowledgeEmbedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $workspace->id, 'language' => 'pl']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function wikilink(string $fromEntryId): KnowledgeLink
    {
        return KnowledgeLink::query()->where('from_entry_id', $fromEntryId)->firstOrFail();
    }

    // ---- the edge ---------------------------------------------------------------------

    public function test_an_inflected_target_is_corrected_to_the_canonical_slug(): void
    {
        $tokyo = $this->makeEntry(
            $this->base,
            'Nowe Tokio',
            'Miasto.',
            KnowledgeEntryType::PLACE,
            'nowe-tokio',
            aliases: ['Nowego Tokio'],
        );

        $source = $this->makeEntry($this->base, 'Cyberdyne', 'Siedziba w [[nowego-tokio]].');

        $link = $this->wikilink((string) $source->id);

        // LIVE, and stored under the canonical address — not merely resolved on the way out.
        $this->assertSame((string) $tokyo->id, (string) $link->to_entry_id, 'the edge is not a ghost');
        $this->assertSame('nowe-tokio', $link->target_slug, 'and it was written down canonically');
    }

    /** A target that matches a real slug is untouched — the correction is only for what would be a ghost. */
    public function test_a_canonical_target_is_left_alone(): void
    {
        $tokyo = $this->makeEntry(
            $this->base,
            'Nowe Tokio',
            'Miasto.',
            KnowledgeEntryType::PLACE,
            'nowe-tokio',
            aliases: ['Nowego Tokio'],
        );

        $source = $this->makeEntry($this->base, 'IceBreaker', 'Dziala w [[nowe-tokio]].');

        $this->assertSame((string) $tokyo->id, (string) $this->wikilink((string) $source->id)->to_entry_id);
        $this->assertSame('nowe-tokio', $this->wikilink((string) $source->id)->target_slug);
    }

    /**
     * THE COLLISION, RESOLVED THE ONLY WAY IT CAN BE: an address beats somebody else's nickname.
     *
     * One entry's alias slugifies to another entry's slug. Re-pointing the link at the alias holder
     * would move a live link onto a different subject — the writer typed the thing that IS an
     * identifier, and identifiers win.
     */
    public function test_a_slug_beats_another_entrys_alias(): void
    {
        $real = $this->makeEntry($this->base, 'Stare Tokio', 'Miasto.', KnowledgeEntryType::PLACE, 'stare-tokio');

        $this->makeEntry(
            $this->base,
            'Nowe Tokio',
            'Inne miasto.',
            KnowledgeEntryType::PLACE,
            'nowe-tokio',
            // ...whose alias happens to slugify onto the OTHER entry's address.
            aliases: ['Stare Tokio'],
        );

        $source = $this->makeEntry($this->base, 'Kronika', 'Wzmianka o [[stare-tokio]].');

        $this->assertSame(
            (string) $real->id,
            (string) $this->wikilink((string) $source->id)->to_entry_id,
            'the link means the entry that owns the address',
        );
    }

    // ---- the existing data ------------------------------------------------------------

    /**
     * A GHOST LEFT BY AN INFLECTED LINK IS ADOPTED when the entry that owns the alias appears.
     *
     * This is what repairs a base written before the correction existed: nothing has to be re-saved by
     * hand. A ghost points at nothing, so adoption can lose nothing.
     */
    public function test_a_ghost_is_adopted_by_the_entry_whose_alias_it_names(): void
    {
        $source = $this->makeEntry($this->base, 'Cyberdyne', 'Siedziba w [[nowego-tokio]].');

        $this->assertNull($this->wikilink((string) $source->id)->to_entry_id, 'a ghost, for now');

        $tokyo = $this->makeEntry(
            $this->base,
            'Nowe Tokio',
            'Miasto.',
            KnowledgeEntryType::PLACE,
            'nowe-tokio',
            aliases: ['Nowego Tokio'],
        );

        $this->assertSame(
            (string) $tokyo->id,
            (string) $this->wikilink((string) $source->id)->fresh()->to_entry_id,
            'the dead link came back on its own',
        );
    }

    // ---- the seed --------------------------------------------------------------------

    /**
     * FILLING A RED LINK THAT IS REALLY AN ALIAS IS REFUSED BY NAME, before any AI call.
     *
     * The old behaviour was a paid run ending in `seed_missed` — a failure code for a request that was
     * never satisfiable. What a person needs to hear is which entry it already is.
     */
    public function test_seeding_a_session_with_an_alias_is_refused_and_names_the_entry(): void
    {
        $tokyo = $this->makeEntry(
            $this->base,
            'Nowe Tokio',
            'Miasto.',
            KnowledgeEntryType::PLACE,
            'nowe-tokio',
            aliases: ['Nowego Tokio'],
        );

        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => []]));
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => []]));

        try {
            app(KnowledgeDraftSessionService::class)->start(
                $this->base,
                'Material o nowym miescie.',
                'nowego-tokio',
                'Nowego Tokio',
            );

            $this->fail('seeding on an alias should have been refused');
        } catch (KnowledgeSeedIsAliasException $refused) {
            $payload = $refused->render()->getData(true);

            $this->assertSame(409, $refused->render()->getStatusCode());
            $this->assertSame('knowledge_seed_is_alias', $payload['code']);
            // The client can turn "create it" into "open it" without a second request.
            $this->assertSame((string) $tokyo->id, $payload['entry']['id']);
            $this->assertSame('nowe-tokio', $payload['entry']['slug']);
            $this->assertSame('Nowe Tokio', $payload['entry']['title']);
        }
    }

    /** A seed naming an address nothing answers to is still a perfectly good request. */
    public function test_seeding_a_genuinely_missing_entry_still_starts(): void
    {
        $this->makeEntry($this->base, 'Nowe Tokio', 'Miasto.', KnowledgeEntryType::PLACE, 'nowe-tokio', aliases: ['Nowego Tokio']);

        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => []]));
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => [[
            'action' => 'create', 'ref' => 'N1', 'slug' => 'stare-tokio', 'title' => 'Stare Tokio',
            'type' => 'place', 'content' => 'Inne miasto.', 'metadata' => [],
        ]]]));

        $session = app(KnowledgeDraftSessionService::class)->start(
            $this->base,
            'Material o starym miescie.',
            'stare-tokio',
            'Stare Tokio',
        );

        $this->assertNotNull($session->id);
    }

    // ---- the instruction --------------------------------------------------------------

    /** The rule that stops this recurring: the target is the slug, the inflection is the label. */
    public function test_the_instruction_forbids_inflecting_a_link_target(): void
    {
        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => []]));
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => [[
            'action' => 'create', 'ref' => 'N1', 'slug' => 'x', 'title' => 'X',
            'type' => 'concept', 'content' => 'Tresc.', 'metadata' => [],
        ]]]));

        $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => 'Material zrodlowy.',
        ])->assertCreated();

        KnowledgeDraftAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'THE TARGET IS ALWAYS THE CANONICAL SLUG')
                && str_contains($instructions, 'WRONG:  [[nowego-tokio]]')
                && str_contains($instructions, 'RIGHT:  [[nowe-tokio|Nowego Tokio]]');
        });
    }
}
