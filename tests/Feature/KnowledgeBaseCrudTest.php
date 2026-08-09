<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Database\Factories\KnowledgeBaseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 — CRUD for KNOWLEDGE BASES, and the governance split that makes a shared base safe to share:
 * any member may create one and edit its name, but rewriting its CHARTER or its METADATA SCHEMA (the
 * contract every entry is validated against) is limited to the base's creator or the workspace owner.
 */
class KnowledgeBaseCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();

        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach([$this->owner->id, $this->member->id]);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function actingAsMember(User $user): self
    {
        $this->actingAs($user)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /** Build models as another workspace's tenant (mirrors CrossWorkspaceBindingTest). */
    private function within(Workspace $workspace, Closure $build)
    {
        $context = app(TenantContext::class);
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $context->set($this->workspace);
        }
    }

    // ---- happy paths ----------------------------------------------------------

    public function test_a_member_can_create_a_base_with_a_metadata_schema(): void
    {
        $this->actingAsMember($this->member)
            ->postJson('/api/knowledge/bases', [
                'name' => 'Baza produktowa',
                'description' => 'Fakty o produkcie',
                'charter' => 'Trzymamy tu wyłącznie zweryfikowane fakty.',
                'language' => 'pl',
                'metadata_schema' => [
                    ['key' => 'zrodlo', 'label' => 'Źródło', 'descriptor' => ['base' => 'text', 'nullable' => true, 'array' => false]],
                    ['key' => 'pewnosc', 'label' => 'Pewność', 'descriptor' => ['base' => 'number', 'nullable' => false, 'array' => false]],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Baza produktowa')
            ->assertJsonPath('data.language', 'pl')
            ->assertJsonPath('data.metadata_schema.0.key', 'zrodlo')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_be_managed', true);

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => 'Baza produktowa',
            'creator_id' => $this->member->id,
            'workspace_id' => $this->workspace->id,
        ]);
    }

    public function test_language_defaults_to_the_app_locale(): void
    {
        $this->actingAsMember($this->member)
            ->postJson('/api/knowledge/bases', ['name' => 'Bez języka'])
            ->assertCreated()
            ->assertJsonPath('data.language', substr(app()->getLocale(), 0, 5));
    }

    public function test_index_lists_bases_with_their_entry_counts(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->member->id]);
        KnowledgeEntry::factory()->count(3)->create(['knowledge_base_id' => $base->id]);

        $this->actingAsMember($this->member)
            ->getJson('/api/knowledge/bases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entries_count', 3);
    }

    public function test_show_returns_the_base(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->member->id]);

        $this->actingAsMember($this->member)
            ->getJson("/api/knowledge/bases/{$base->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $base->id);
    }

    // ---- schema validation ----------------------------------------------------

    public function test_a_schema_field_with_an_unsafe_key_is_rejected(): void
    {
        $this->actingAsMember($this->member)
            ->postJson('/api/knowledge/bases', [
                'name' => 'Zła baza',
                'metadata_schema' => [
                    ['key' => '9zle', 'label' => 'Złe', 'descriptor' => ['base' => 'text']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata_schema.0.key');
    }

    public function test_a_schema_field_with_a_non_authorable_base_is_rejected(): void
    {
        // `file` is a template-slot base, deliberately NOT part of a knowledge field's surface.
        $this->actingAsMember($this->member)
            ->postJson('/api/knowledge/bases', [
                'name' => 'Zła baza',
                'metadata_schema' => [
                    ['key' => 'zalacznik', 'label' => 'Załącznik', 'descriptor' => ['base' => 'file']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata_schema.0.descriptor.base');
    }

    public function test_duplicate_schema_keys_are_rejected(): void
    {
        $this->actingAsMember($this->member)
            ->postJson('/api/knowledge/bases', [
                'name' => 'Zła baza',
                'metadata_schema' => [
                    ['key' => 'zrodlo', 'label' => 'A', 'descriptor' => ['base' => 'text']],
                    ['key' => 'zrodlo', 'label' => 'B', 'descriptor' => ['base' => 'text']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metadata_schema.1.key');
    }

    public function test_template_directives_in_the_charter_are_rejected(): void
    {
        $this->actingAsMember($this->member)
            ->postJson('/api/knowledge/bases', [
                'name' => 'Baza',
                'charter' => 'Zawsze pisz {{ globals.nazwa_marki }} w nagłówku.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('charter');
    }

    // ---- governance -----------------------------------------------------------

    public function test_a_member_may_rename_someone_elses_base(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->owner->id]);

        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$base->id}", ['name' => 'Nowa nazwa'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nowa nazwa');
    }

    public function test_a_member_may_not_change_the_schema_of_someone_elses_base(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->owner->id]);

        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$base->id}", [
                'name' => $base->name,
                'metadata_schema' => [
                    ['key' => 'zrodlo', 'label' => 'Źródło', 'descriptor' => ['base' => 'text']],
                ],
            ])
            ->assertForbidden();
    }

    public function test_a_member_may_not_change_the_charter_of_someone_elses_base(): void
    {
        $base = KnowledgeBase::factory()->withCharter('Stary charter')->create(['creator_id' => $this->owner->id]);

        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$base->id}", [
                'name' => $base->name,
                'charter' => 'Przejmuję tę bazę',
            ])
            ->assertForbidden();
    }

    public function test_echoing_back_an_unchanged_charter_is_not_a_governance_change(): void
    {
        $base = KnowledgeBase::factory()->withCharter('Stary charter')->create(['creator_id' => $this->owner->id]);

        // A full-object PATCH from a form resends every field; only a real change needs `manage`.
        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$base->id}", [
                'name' => 'Nowa nazwa',
                'charter' => 'Stary charter',
                'metadata_schema' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nowa nazwa');
    }

    /**
     * REGRESSION. A PATCH that only renames the base must not resolve the omitted charter/schema to
     * empty: that would let any member destroy the base's governance data through an edit they ARE
     * allowed to make, and the authorization split would never see it (the payload mentions neither).
     */
    public function test_a_partial_patch_does_not_wipe_the_charter_or_the_schema(): void
    {
        $base = KnowledgeBase::factory()
            ->withCharter('Zasady tej bazy')
            ->withSchema([KnowledgeBaseFactory::field('zrodlo')])
            ->create(['creator_id' => $this->owner->id]);

        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$base->id}", ['name' => 'Tylko nazwa'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tylko nazwa')
            ->assertJsonPath('data.charter', 'Zasady tej bazy')
            ->assertJsonPath('data.metadata_schema.0.key', 'zrodlo');
    }

    public function test_the_base_creator_may_change_its_schema(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->member->id]);

        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$base->id}", [
                'name' => $base->name,
                'metadata_schema' => [
                    KnowledgeBaseFactory::field('zrodlo'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.metadata_schema.0.key', 'zrodlo');
    }

    public function test_the_workspace_owner_may_change_another_members_base_schema(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->member->id]);

        $this->actingAsMember($this->owner)
            ->patchJson("/api/knowledge/bases/{$base->id}", [
                'name' => $base->name,
                'metadata_schema' => [KnowledgeBaseFactory::field('zrodlo')],
            ])
            ->assertOk();
    }

    public function test_a_member_may_not_delete_someone_elses_base(): void
    {
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->owner->id]);

        $this->actingAsMember($this->member)
            ->deleteJson("/api/knowledge/bases/{$base->id}")
            ->assertForbidden();
    }

    // ---- tenancy --------------------------------------------------------------

    public function test_a_request_without_an_active_workspace_is_refused(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/knowledge/bases')
            ->assertStatus(400);
    }

    public function test_a_cross_workspace_base_id_404s_at_bind(): void
    {
        $stranger = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $other->users()->attach($stranger->id);

        $foreign = $this->within($other, fn () => KnowledgeBase::factory()->create(['creator_id' => $stranger->id]));

        $this->actingAsMember($this->member)
            ->getJson("/api/knowledge/bases/{$foreign->id}")
            ->assertNotFound();

        $this->actingAsMember($this->member)
            ->patchJson("/api/knowledge/bases/{$foreign->id}", ['name' => 'Przejęcie'])
            ->assertNotFound();

        $this->actingAsMember($this->member)
            ->getJson("/api/knowledge/bases/{$foreign->id}/entries")
            ->assertNotFound();
    }

    public function test_a_cross_workspace_entry_id_404s_at_bind(): void
    {
        $stranger = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $stranger->id]);
        $other->users()->attach($stranger->id);

        $foreign = $this->within($other, function () use ($stranger) {
            $base = KnowledgeBase::factory()->create(['creator_id' => $stranger->id]);

            return KnowledgeEntry::factory()->create([
                'knowledge_base_id' => $base->id,
                'creator_id' => $stranger->id,
            ]);
        });

        $this->actingAsMember($this->member)
            ->getJson("/api/knowledge/entries/{$foreign->id}")
            ->assertNotFound();
    }
}
