<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionExecutor;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CROSS-PART CONTEXT (video_script rework — Phase A): a content-type PART may reference the GENERATED
 * OUTPUT of an EARLIER part via the new `parts` variable source root. This pins the load-bearing,
 * highest-risk invariants:
 *   - SEQUENTIAL ACCUMULATION: in a whole run, part 2's body/prompt sees part 1's rendered output.
 *   - REFINE-TIME SEEDING (the #1 risk): a per-part op (regenerate/refine) seeds `parts` from the CURRENT
 *     stored results (the already-generated earlier parts), NOT by re-running upstream.
 *   - INJECTION: a `parts.*` value is DATA — it rides the NUL-mask, never re-interpreted as a directive.
 *   - EARLIER-ONLY + ACYCLIC write validation: `parts.<earlierKey>` accepted; a self/forward/unknown part
 *     reference is a 422.
 *   - CATALOG scoping: the catalog offers only the EARLIER parts as `parts.<key>`.
 *   - STALENESS: refining an upstream part marks downstream `parts.<key>`-referencing parts `stale`; a full
 *     generate clears it.
 *
 * The video_script type ([script (text), scene_plan]) is used because a scene narration is a text body that
 * can reference `parts.script` — a text-only cross-part reference with no image chain to run.
 */
class CrossPartContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        // Creative-direction layer OFF: these cases pin run behavior that PREDATES it, and an un-scripted
        // derivation would be a REAL provider call. The layer is covered by CreativeDirectionTest.
        config()->set('generator.direction.enabled', false);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------------

    private function directive(string $id): string
    {
        return TemplateFactory::directive($id);
    }

    /**
     * A transitional FLAT `{{…}}` token (NOT an `@[variable]` directive). The resolver's flat pass resolves
     * these as live references, so the shared scanner must see them too — used to prove a flat `parts.*` token
     * is caught by staleness + write validation (the flat directive regex the scan used never scanned them).
     */
    private function flat(string $id): string
    {
        return '{{' . $id . '}}';
    }

    /**
     * An `@[ai-text]("…")` directive whose PROMPT embeds $inner markdown (which may itself carry a nested
     * `@[variable]` chip) — the double-escaped byte-format the editor emits and the resolver walks. Used to
     * prove a `parts.*` reference NESTED in an ai-text prompt is seen by the shared scanner (staleness +
     * write validation) — the flat directive regex is blind to it.
     */
    private function aiText(string $inner): string
    {
        $payload = json_encode(['v' => 1, 'data' => ['id' => 'ai_1', 'personaId' => null, 'prompt' => $inner, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /**
     * A fenced if-block whose IF branch CONDITION references $variableId (the `[[IF …]]` marker's
     * `variableId`, NOT an `@[variable]` directive) and whose body is a literal — used to prove a `parts.*`
     * reference carried in an if-block CONDITION is seen by the shared scanner.
     */
    private function ifBlockOnCondition(string $variableId): string
    {
        $condition = ['variableId' => $variableId, 'pipeline' => []];

        return '```if-block ' . json_encode(['id' => 'if_1', 'v' => 1]) . "\n"
            . '[[IF ' . json_encode(['id' => 'b1', 'condition' => $condition]) . ']]' . "\n"
            . 'Recap present.' . "\n"
            . '[[ELSE ' . json_encode(['id' => 'b2']) . ']]' . "\n"
            . 'No recap.' . "\n" . '```';
    }

    private function topicSlot(): array
    {
        return ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    /** A video_script snapshot: a script body + a one-scene plan whose narration references `parts.script`. */
    private function videoScriptContent(string $scriptMarkdown, string $narrationMarkdown): array
    {
        return [
            'script' => ['markdown' => $scriptMarkdown],
            'scene_plan' => ['scenes' => [['narration' => ['markdown' => $narrationMarkdown]]]],
        ];
    }

    private function runJob(GenerationSession $session): void
    {
        (new RunGenerationSessionJob($session->id, $this->workspace->id))->handle(app(GenerationSessionRunManager::class));
    }

    /** Claim a part op (regenerate/full) and run its worker job — the async path minus the queue. */
    private function claimAndRun(GenerationSession $session, GenerationRunMode $mode, ?string $partKey = null): GenerationSession
    {
        Queue::fake();
        app(GenerationSessionRunManager::class)->claimAndDispatch($session, $mode, $partKey);

        (new RunGenerationSessionJob($session->id, $this->workspace->id, $mode->value, $partKey, null))
            ->handle(app(GenerationSessionRunManager::class));

        return $session->fresh();
    }

    // ---- sequential accumulation (whole run) -----------------------------------

    public function test_a_later_part_sees_an_earlier_parts_rendered_output_in_a_whole_run(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'video_script',
                $this->videoScriptContent('The topic is ' . $this->directive('slots.topic'), 'Recap: ' . $this->directive('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        // Part 1 rendered.
        $this->assertSame('The topic is Widgets', $session->results['script']['text']);
        // Part 2's narration saw part 1's rendered output through the `parts` root.
        $this->assertSame('Recap: The topic is Widgets', $session->results['scene_plan']['scenes'][0]['narration']);
    }

    public function test_a_parts_value_is_data_and_is_never_re_interpreted_as_a_reference(): void
    {
        // The slot value carries reference-like bytes; the script echoes it, so `parts.script` holds them.
        // A later narration embedding `parts.script` must treat them as DATA (verbatim), never resolve the
        // `{{globals.leak}}` flat token or the `@[…]` directive a second time (injection-hardened NUL-mask).
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Generating)
            ->snapshot(
                'video_script',
                $this->videoScriptContent($this->directive('slots.topic'), 'Body: ' . $this->directive('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'INJECT{{globals.leak}}END'],
            )
            ->create(['creator_id' => $this->user->id]);

        $this->runJob($session);

        $session->refresh();
        $this->assertSame('INJECT{{globals.leak}}END', $session->results['script']['text']);
        // Verbatim — the `{{globals.leak}}` token inside the part value was NOT re-scanned/resolved.
        $this->assertSame('Body: INJECT{{globals.leak}}END', $session->results['scene_plan']['scenes'][0]['narration']);
    }

    // ---- refine-time seeding (the #1 risk) -------------------------------------

    public function test_regenerating_a_later_part_seeds_parts_from_the_current_results_not_by_rerunning_upstream(): void
    {
        // The SNAPSHOT script differs from the STORED script result. A regenerate of the scene_plan must seed
        // `parts.script` from the CURRENT stored result ('CURRENT SCRIPT'), never re-run the upstream script
        // (which would produce 'FRESH ...'). An empty seed would drop the recap entirely — the incoherence
        // this whole feature exists to prevent.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent('FRESH ' . $this->directive('slots.topic'), 'Recap: ' . $this->directive('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'script' => ['kind' => 'script', 'status' => 'ok', 'text' => 'CURRENT SCRIPT', 'version' => 1],
                    'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [['narration' => 'stale recap']], 'version' => 1],
                ],
            ]);

        $result = app(GenerationSessionExecutor::class)->renderPartFromSnapshot($session, 'scene_plan');

        $this->assertNotNull($result);
        // The narration saw the CURRENT script result (from results), NOT a re-run of the snapshot script.
        $this->assertSame('Recap: CURRENT SCRIPT', $result['scenes'][0]['narration']);
        $this->assertStringNotContainsString('FRESH', $result['scenes'][0]['narration']);
    }

    public function test_a_forward_part_reference_seeds_empty_in_both_a_full_run_and_a_refine(): void
    {
        // C2 defense-in-depth: construct a snapshot the write gate would REJECT (the script references a LATER
        // part) DIRECTLY at the executor level, to pin seedParts strictly-earlier independent of write
        // validation. The stored scene_plan result is contrived to make a TEXT contribution; the OLD whole-map
        // seed would leak 'LATER TEXT' into a script refine. Strictly-earlier seeding for the script target
        // (nothing earlier) must NOT leak it — parts.scene_plan resolves EMPTY, exactly as in a full run where
        // the script renders before scene_plan exists.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent('Script[' . $this->directive('parts.scene_plan') . ']', 'A scene.'),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'scene_plan' => ['kind' => 'text_body', 'status' => 'ok', 'text' => 'LATER TEXT', 'version' => 1],
                ],
            ]);

        $executor = app(GenerationSessionExecutor::class);

        // A refine/regenerate of the script: strictly-earlier seed for 'script' is EMPTY → the forward
        // parts.scene_plan ref resolves empty, never the stored 'LATER TEXT'.
        $refined = $executor->renderPartFromSnapshot($session, 'script');
        $this->assertSame('Script[]', $refined['text']);
        $this->assertStringNotContainsString('LATER TEXT', $refined['text']);

        // A full run accumulates from empty → the script sees the SAME empty forward reference (equivalence).
        $full = $executor->execute($session);
        $this->assertSame('Script[]', $full['script']['text']);
    }

    // ---- write validation: earlier-only + acyclic ------------------------------

    /**
     * A post_with_image payload — the vehicle for cross-part WRITE validation now that video_script composes
     * shot_list/storyboard (which expose no `parts.*`-authoring body). Part 1 is the BODY (text); part 2 is the
     * IMAGE, whose ai_generate base prompt is a body, so a `parts.body` reference in it type-checks exactly as a
     * scene narration referencing `parts.script` did. Forward/self references live in the BODY (referencing the
     * later image / itself); earlier references live in the IMAGE prompt (referencing the body).
     */
    private function postWithImagePayload(string $bodyMarkdown, string $imagePrompt): array
    {
        return [
            'name' => 'T',
            'content_type' => 'post_with_image',
            'slots' => [$this->topicSlot()],
            'content' => [
                'body' => ['markdown' => $bodyMarkdown],
                'image' => ['base' => ['kind' => 'ai_generate', 'prompt' => $imagePrompt], 'filters' => []],
            ],
        ];
    }

    public function test_a_later_part_referencing_an_earlier_part_is_accepted(): void
    {
        // The image (part 2) references `parts.body` (part 1) → earlier → accepted.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'About ' . $this->directive('slots.topic') . '.',
            'A poster of ' . $this->directive('parts.body'),
        ))->assertCreated();
    }

    public function test_a_forward_part_reference_is_rejected(): void
    {
        // The body (part 1) references `parts.image` (a LATER part) → not offered → 422.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'Teaser ' . $this->directive('parts.image'),
            'A poster.',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_a_self_part_reference_is_rejected(): void
    {
        // The body (the FIRST part) references `parts.body` (itself) → no earlier parts → 422.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'Loop ' . $this->directive('parts.body'),
            'A poster.',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_an_unknown_part_reference_is_rejected(): void
    {
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'About ' . $this->directive('slots.topic') . '.',
            'A poster of ' . $this->directive('parts.ghost'),
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.base.prompt']);
    }

    public function test_a_forward_part_reference_nested_in_an_ai_text_prompt_is_rejected(): void
    {
        // C2: the body (part 1) references `parts.image` (a LATER part) ONLY inside an @[ai-text] prompt. A flat
        // directive regex is blind to the nested ref, so it slipped past the earlier-only gate; the shared
        // scanner finds it → 422 (keeps the cross-part graph acyclic).
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'Teaser ' . $this->aiText('Base it on ' . $this->directive('parts.image')),
            'A poster.',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_a_self_part_reference_in_an_if_block_condition_is_rejected(): void
    {
        // C2: the body (the FIRST part) references `parts.body` (itself) in an if-block CONDITION — no earlier
        // parts exist, so a self reference is forward-equivalent → 422. The ref lives in the marker, never in an
        // @[variable] directive, so only the shared scanner catches it.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            "Loop\n" . $this->ifBlockOnCondition('parts.body'),
            'A poster.',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_a_forward_flat_part_reference_is_rejected(): void
    {
        // C2 (flat serialization): the body (part 1) references `parts.image` (a LATER part) via a FLAT
        // `{{parts.image}}` token. The resolver resolves flat tokens as references, but the earlier-only scan
        // never scanned them, so the forward ref slipped the gate; the shared scanner now catches it → 422.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'Teaser ' . $this->flat('parts.image'),
            'A poster.',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_a_self_flat_part_reference_is_rejected(): void
    {
        // C2 (flat serialization): the body (the FIRST part) references `parts.body` (itself) via a FLAT token
        // — no earlier parts exist, so a self reference is forward-equivalent → 422.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'Loop ' . $this->flat('parts.body'),
            'A poster.',
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body.markdown']);
    }

    public function test_an_earlier_flat_part_reference_is_accepted(): void
    {
        // The mirror of the rejection: an EARLIER part reference via a FLAT `{{parts.body}}` token in the image
        // prompt is a valid cross-part reference and is accepted — the scanner gate rejects only forward/self/unknown.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'About ' . $this->directive('slots.topic') . '.',
            'A poster of ' . $this->flat('parts.body'),
        ))->assertCreated();
    }

    public function test_an_earlier_part_reference_nested_in_an_ai_text_prompt_is_accepted(): void
    {
        // The mirror of the rejection: an EARLIER part reference nested in an ai-text prompt (in the image base)
        // type-checks and is accepted — the scanner gate rejects only forward/self/unknown, never a valid earlier
        // reference.
        $this->postJson('/api/generator/templates', $this->postWithImagePayload(
            'About ' . $this->directive('slots.topic') . '.',
            'Illustrate ' . $this->aiText('Continue from ' . $this->directive('parts.body')),
        ))->assertCreated();
    }

    // ---- catalog scoping -------------------------------------------------------

    public function test_the_catalog_offers_only_earlier_parts_for_the_scoped_part(): void
    {
        // Scoped to image (part 2 of post_with_image): the earlier `body` part is offered as `parts.body`.
        $variables = $this->postJson('/api/generator/catalog', [
            'slots' => [$this->topicSlot()],
            'content_type' => 'post_with_image',
            'part_key' => 'image',
        ])->assertOk()->json('data.variables');

        $parts = array_column($variables, 'path');
        $this->assertContains('parts.body', $parts);

        $partVar = collect($variables)->firstWhere('path', 'parts.body');
        $this->assertSame('parts', $partVar['source']);
        $this->assertSame('text', $partVar['type']);
    }

    public function test_the_catalog_offers_no_parts_for_the_first_part_or_when_unscoped(): void
    {
        // Scoped to body (part 1): nothing earlier → no `parts.*`.
        $first = $this->postJson('/api/generator/catalog', [
            'slots' => [$this->topicSlot()],
            'content_type' => 'post_with_image',
            'part_key' => 'body',
        ])->assertOk()->json('data.variables');
        $this->assertEmpty(array_filter(array_column($first, 'source'), fn ($s) => $s === 'parts'));

        // Unscoped (no content_type / part_key) — unchanged behavior for callers that don't scope.
        $unscoped = $this->postJson('/api/generator/catalog', ['slots' => [$this->topicSlot()]])
            ->assertOk()->json('data.variables');
        $this->assertEmpty(array_filter(array_column($unscoped, 'source'), fn ($s) => $s === 'parts'));
    }

    // ---- staleness -------------------------------------------------------------

    public function test_regenerating_an_upstream_part_marks_the_downstream_dependent_stale(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent($this->directive('slots.topic'), 'Recap: ' . $this->directive('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'script' => ['kind' => 'script', 'status' => 'ok', 'text' => 'OLD SCRIPT', 'version' => 1],
                    'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [['narration' => 'Recap: OLD SCRIPT']], 'version' => 1],
                ],
            ]);

        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'script');

        // The script's output changed; the downstream scene_plan (which references parts.script) is now stale.
        $this->assertTrue($session->results['scene_plan']['stale']);
        // The regenerated part itself carries no stale flag (its result was replaced fresh).
        $this->assertArrayNotHasKey('stale', $session->results['script']);

        // The Resource emits results verbatim, so the FE reads the flag straight off results.
        $this->getJson("/api/generator/sessions/{$session->id}")
            ->assertJsonPath('data.results.scene_plan.stale', true);
    }

    public function test_regenerating_upstream_marks_a_downstream_that_references_it_via_an_ai_text_prompt_stale(): void
    {
        // C1: the downstream narration references `parts.script` ONLY inside an @[ai-text] PROMPT (double-
        // escaped). The flat directive regex the staleness scan used could not walk into the prompt, so the
        // dependent was silently NOT marked stale. The shared scanner descends into the prompt, so it is.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent($this->directive('slots.topic'), $this->aiText('Recap: ' . $this->directive('parts.script'))),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'script' => ['kind' => 'script', 'status' => 'ok', 'text' => 'OLD SCRIPT', 'version' => 1],
                    'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [['narration' => 'Recap: OLD SCRIPT']], 'version' => 1],
                ],
            ]);

        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'script');

        $this->assertTrue($session->results['scene_plan']['stale']);
    }

    public function test_regenerating_upstream_marks_a_downstream_that_references_it_via_an_if_block_condition_stale(): void
    {
        // C1: the downstream narration references `parts.script` ONLY in an if-block CONDITION marker (the ref
        // is in `[[IF {"variableId":"parts.script"}]]`, not an @[variable] directive). The flat directive regex
        // never sees a marker, so the dependent was not marked stale; the shared scanner reads the condition.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent($this->directive('slots.topic'), $this->ifBlockOnCondition('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'script' => ['kind' => 'script', 'status' => 'ok', 'text' => 'OLD SCRIPT', 'version' => 1],
                    'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [['narration' => 'Recap present.']], 'version' => 1],
                ],
            ]);

        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'script');

        $this->assertTrue($session->results['scene_plan']['stale']);
    }

    public function test_regenerating_upstream_marks_a_downstream_that_references_it_via_a_flat_token_stale(): void
    {
        // C1 (flat serialization): the downstream narration references `parts.script` ONLY via a FLAT
        // `{{parts.script}}` token — which the resolver's flat pass DOES resolve, but the scan path never
        // scanned. Without the flat scan branch the dependent was silently NOT marked stale; the shared
        // scanner now sees the flat token exactly as the resolver resolves it.
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent($this->directive('slots.topic'), 'Recap: ' . $this->flat('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'script' => ['kind' => 'script', 'status' => 'ok', 'text' => 'OLD SCRIPT', 'version' => 1],
                    'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [['narration' => 'Recap: OLD SCRIPT']], 'version' => 1],
                ],
            ]);

        $session = $this->claimAndRun($session, GenerationRunMode::Regenerate, 'script');

        $this->assertTrue($session->results['scene_plan']['stale']);
    }

    public function test_a_full_generate_clears_staleness(): void
    {
        $session = GenerationSession::factory()
            ->status(GenerationSessionStatus::Ready)
            ->snapshot(
                'video_script',
                $this->videoScriptContent($this->directive('slots.topic'), 'Recap: ' . $this->directive('parts.script')),
                [$this->topicSlot()],
                ['topic' => 'Widgets'],
            )
            ->create([
                'creator_id' => $this->user->id,
                'results' => [
                    'script' => ['kind' => 'script', 'status' => 'ok', 'text' => 'OLD', 'version' => 1],
                    'scene_plan' => ['kind' => 'scene_plan', 'status' => 'ok', 'scenes' => [], 'version' => 1, 'stale' => true],
                ],
            ]);

        $session = $this->claimAndRun($session, GenerationRunMode::Full);

        // A full run rebuilds every part fresh (coherent by construction) → the stale flag is gone.
        $this->assertSame(GenerationSessionStatus::Ready, $session->status);
        $this->assertArrayNotHasKey('stale', $session->results['scene_plan']);
    }
}
