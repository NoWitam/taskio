<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workspaces\Models\Workspace;
use Database\Factories\ConstantFactory;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The TEMPLATE SLOT + BODY write-validation (TemplateSlotValidator, orchestrated by TemplateContentValidator):
 * each declared SLOT's identity + descriptor, and a text_body part's `content.body.markdown` `@[variable]`
 * directive references + pipelines against the template catalog. Pins that a bad descriptor, an unknown
 * `slots.<name>`, and a non-type-checking pipeline are all rejected at write (the directive errors now
 * RELOCATED under `content.body.markdown`), while a well-typed template is accepted — so the CRUD write path
 * is the ONE gate a template passes and it can never persist a shape the resolver/catalog cannot handle.
 */
class TemplateSlotValidationTest extends TestCase
{
    use RefreshDatabase;

    /** The `@[variable]("<payload>")` byte-format the resolver + the write-validator read. */
    private function directive(string $id, array $pipeline = []): string
    {
        return TemplateFactory::directive($id, $pipeline);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Post',
            'content_type' => 'post',
            'content' => ['body' => ['markdown' => 'Body']],
            'slots' => [
                ['name' => 'topic', 'description' => 'Subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ],
        ], $overrides);
    }

    /** A `post` payload whose body markdown is $markdown (the text_body part). */
    private function bodyPayload(string $markdown, array $slots): array
    {
        return $this->payload(['content' => ['body' => ['markdown' => $markdown]], 'slots' => $slots]);
    }

    // ---- slot descriptor + identity ------------------------------------------

    /** The fixed file COMPOSITE descriptor a file SLOT declares ({base:file, fields:[id,name,type,size,url]}). */
    private function fileDescriptor(): array
    {
        return TemplateFactory::fileDescriptor();
    }

    /** An OBJECT slot with two typed leaves ({name:text, price:number}). */
    private function objectSlot(string $name = 'product'): array
    {
        $scalar = fn (string $base): array => ['base' => $base, 'nullable' => false, 'array' => false];

        return [
            'name' => $name,
            'descriptor' => [
                'base' => 'object',
                'nullable' => false,
                'array' => false,
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'descriptor' => $scalar('text')],
                    ['key' => 'price', 'label' => 'Price', 'descriptor' => $scalar('number')],
                ],
            ],
        ];
    }

    public function test_a_slot_with_a_bad_descriptor_base_is_rejected(): void
    {
        $user = User::factory()->create();

        foreach (['time', 'nonsense'] as $base) {
            $this->actingAs($user)
                ->postJson('/api/generator/templates', $this->payload([
                    'slots' => [['name' => 'topic', 'descriptor' => ['base' => $base]]],
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['slots.0.descriptor.base']);
        }
    }

    public function test_a_file_slot_is_accepted(): void
    {
        $user = User::factory()->create();

        // A file is a legit REUSABLE template input (its composite subfields interpolate into a body).
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload([
                'slots' => [['name' => 'image', 'description' => 'Hero image', 'descriptor' => $this->fileDescriptor()]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.slots.0.name', 'image');
    }

    public function test_a_malformed_file_slot_descriptor_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload([
                'slots' => [['name' => 'image', 'descriptor' => ['base' => 'file']]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slots.0.descriptor.fields']);
    }

    public function test_an_object_slot_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload([
                'slots' => [$this->objectSlot()],
            ]))
            ->assertCreated();
    }

    public function test_an_enum_slot_requires_options(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload([
                'slots' => [['name' => 'tone', 'descriptor' => ['base' => 'enum']]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slots.0.descriptor.options']);
    }

    public function test_a_slot_name_must_be_a_safe_identifier(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload([
                'slots' => [['name' => 'my topic', 'descriptor' => ['base' => 'text']]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slots.0.name']);
    }

    public function test_a_slot_name_may_not_be_a_reserved_root(): void
    {
        $user = User::factory()->create();

        foreach (['slots', 'globals', 'input'] as $reserved) {
            $this->actingAs($user)
                ->postJson('/api/generator/templates', $this->payload([
                    'slots' => [['name' => $reserved, 'descriptor' => ['base' => 'text']]],
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['slots.0.name']);
        }
    }

    public function test_slot_names_must_be_distinct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload([
                'slots' => [
                    ['name' => 'topic', 'descriptor' => ['base' => 'text']],
                    ['name' => 'topic', 'descriptor' => ['base' => 'number']],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slots.1.name']);
    }

    // ---- body directive references + pipelines (relocated to content.body.markdown) ----

    public function test_body_referencing_an_unknown_slot_is_rejected(): void
    {
        $user = User::factory()->create();

        // The body references `slots.headline`, but only `topic` is declared.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Title: ' . $this->directive('slots.headline'),
                [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_a_pipeline_that_does_not_type_check_is_rejected(): void
    {
        $user = User::factory()->create();

        // A TEXT slot piped through a NUMBER-only op (num_abs) cannot type-flow — error under the part path.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Value: ' . $this->directive('slots.topic', [['op' => 'num_abs']]),
                [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown.0.op']);
    }

    public function test_a_reference_to_an_unknown_root_is_not_a_reference_and_is_accepted(): void
    {
        $user = User::factory()->create();

        // A directive whose root is not a context root (`foo`) is literal text to the resolver, never a
        // reference — so it is NOT a write error (it renders inert at preview).
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Literal: ' . $this->directive('foo.bar'),
                [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ))
            ->assertCreated();
    }

    public function test_a_well_typed_template_with_a_piped_slot_is_accepted(): void
    {
        $user = User::factory()->create();

        // A TEXT slot piped through text_uppercase (text → text) type-flows and is accepted.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Shout: ' . $this->directive('slots.topic', [['op' => 'text_uppercase']]),
                [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ))
            ->assertCreated()
            ->assertJsonPath('data.slots.0.name', 'topic');
    }

    // ---- object / file SLOT + object GLOBAL subfield references ----------------

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    public function test_an_object_slot_subfield_reference_type_flows_and_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Name: ' . $this->directive('slots.product.name', [['op' => 'text_uppercase']])
                    . ' Price: ' . $this->directive('slots.product.price', [['op' => 'num_abs']]),
                [$this->objectSlot()],
            ))
            ->assertCreated();
    }

    public function test_an_unknown_object_slot_subfield_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Color: ' . $this->directive('slots.product.color'),
                [$this->objectSlot()],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_an_object_slot_subfield_pipeline_type_flows_from_the_subfield_type(): void
    {
        $user = User::factory()->create();

        // slots.product.price is NUMBER; piping it through a TEXT-only op cannot type-flow.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'Price: ' . $this->directive('slots.product.price', [['op' => 'text_uppercase']]),
                [$this->objectSlot()],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown.0.op']);
    }

    public function test_a_file_slot_subfield_reference_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'URL: ' . $this->directive('slots.image.url', [['op' => 'text_uppercase']]),
                [['name' => 'image', 'descriptor' => $this->fileDescriptor()]],
            ))
            ->assertCreated();
    }

    public function test_an_unknown_file_slot_subfield_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'W: ' . $this->directive('slots.image.width'),
                [['name' => 'image', 'descriptor' => $this->fileDescriptor()]],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_an_object_global_subfield_reference_is_accepted(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        Constant::factory()->object('company', [
            ConstantFactory::field('city', VariableType::TEXT->descriptor()),
        ], ['city' => 'Warsaw'])->create(['creator_id' => $user->id, 'workspace_id' => $workspace->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/generator/templates', $this->bodyPayload(
                'City: ' . $this->directive('globals.company.city', [['op' => 'text_uppercase']]),
                [],
            ))
            ->assertCreated();
    }
}
