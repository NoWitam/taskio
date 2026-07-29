<?php

namespace Tests\Unit\Generator;

use App\Modules\Generator\Services\GeneratorAiTextService;
use App\Modules\Generator\Services\ImageChainExecutor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The per-session AI budgets are read as `config(<key>, <fallback>)`, so the LITERAL fallback baked into the
 * code is what applies when the config key is missing (a stripped/overridden config file, a package-discovery
 * miss). Those literals had DRIFTED from the shipped defaults — the `ai_generate` budget fell back to 2 while
 * `config/generator.php` ships 8, silently breaking the documented LOCK-STEP with `storyboard_max_shots` (the
 * last shots of a storyboard would come back frameless), and the ai-text budget fell back to 20 while the
 * config ships 4, silently breaking the run job's TIMEOUT INVARIANT (20 x 60s ≫ the 300s SIGALRM window).
 *
 * This pins them together: a missing key must degrade to EXACTLY the shipped default, never to a value
 * that violates an invariant the config file documents — AND both image budgets must stay in the documented
 * THREE-WAY LOCK-STEP with the shot ceiling (a storyboard fans BOTH out per shot).
 */
class GeneratorBudgetFallbackTest extends TestCase
{
    public function test_the_ai_text_call_budget_falls_back_to_the_shipped_default(): void
    {
        $shipped = (int) config('generator.ai_text_max_calls_per_session');
        $this->assertSame(4, $shipped, 'the shipped default moved — move the code fallback with it');

        $this->forget('ai_text_max_calls_per_session');

        $this->assertSame($shipped, $this->invoke(app(GeneratorAiTextService::class), 'maxCalls'));
    }

    public function test_the_image_generate_budget_falls_back_to_the_shipped_default_in_lock_step_with_the_shot_ceiling(): void
    {
        $shipped = (int) config('generator.image_generate_max_calls_per_session');
        $this->assertSame((int) config('generator.storyboard_max_shots'), $shipped, 'the documented lock-step');

        $this->forget('image_generate_max_calls_per_session');

        $this->assertSame($shipped, $this->invoke(app(ImageChainExecutor::class), 'maxAiGenerations'));
    }

    public function test_the_image_edit_budget_falls_back_to_the_shipped_default_in_lock_step_with_the_shot_ceiling(): void
    {
        // The SAME lock-step as the generate budget, for the sibling reason: ONE authored `ai_edit` filter on a
        // storyboard runs on EVERY shot out of ONE cumulative per-run counter, so a budget below the ceiling
        // silently ships the tail of a full storyboard unfiltered (graceful, but a visibly inconsistent set).
        $shipped = (int) config('generator.image_edit_max_calls_per_session');
        $this->assertSame((int) config('generator.storyboard_max_shots'), $shipped, 'the documented lock-step');

        $this->forget('image_edit_max_calls_per_session');

        $this->assertSame($shipped, $this->invoke(app(ImageChainExecutor::class), 'maxAiEdits'));
    }

    /**
     * REMOVE a generator config key entirely — `config()->set($key, null)` would leave the key PRESENT with a
     * null value, and `config($key, $fallback)` only returns the fallback for a MISSING key. So the whole
     * `generator` array is rewritten without it, which is what a stripped config file actually looks like.
     */
    private function forget(string $key): void
    {
        $generator = (array) config('generator');
        unset($generator[$key]);

        config()->set('generator', $generator);
    }

    /** Call one of the private budget readers (the fallback literal is only observable through them). */
    private function invoke(object $service, string $method): int
    {
        return (new ReflectionMethod($service, $method))->invoke($service);
    }
}
