<?php

namespace Tests\Feature;

use App\Modules\Bot\Http\Controllers\BotSessionDelegationController;
use App\Modules\Bot\Services\BotSlotFillService;
use App\Modules\Generator\Services\SessionDelegationService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pin for the Bot → Generator delegation edge (R2 sub-stage 3). Delegation is the ONE new
 * cross-module edge: the Bot module CALLS the Generator's delegation seams + the Variables ai-text seam,
 * and the Generator NEVER calls back (SessionDelegationService takes primitives/opaque strings/its own
 * model, never a Bot class). This test locks BOTH halves so the direction can never silently invert.
 */
class BotModuleBoundaryTest extends TestCase
{
    /**
     * The Bot delegation seam classes DO depend on Generator + Variables — the deliberate one-way edge: the
     * Bot module reuses the Generator's delegation surface + the shared ai-text seam rather than forking them.
     */
    public function test_bot_delegation_seam_depends_on_generator_and_variables(): void
    {
        $controller = $this->sourceOf(BotSessionDelegationController::class);
        $this->assertStringContainsString('App\\Modules\\Generator', $controller, 'the delegation controller must call the Generator seams.');

        $service = $this->sourceOf(BotSlotFillService::class);
        $this->assertStringContainsString('App\\Modules\\Generator', $service, 'the slot-fill service must call the Generator delegation seam.');
        $this->assertStringContainsString('App\\Modules\\Variables', $service, 'the slot-fill service must call the Variables ai-text seam.');
    }

    /**
     * The reverse is FORBIDDEN: the Generator's delegation seam must name NO Bot class — it takes primitives
     * and opaque strings so the Generator stays Bot-agnostic (the same one-way posture the GeneratorModule
     * boundary test pins across the whole module).
     */
    public function test_the_generator_delegation_seam_never_names_bot(): void
    {
        $this->assertStringNotContainsString(
            'App\\Modules\\Bot',
            $this->sourceOf(SessionDelegationService::class),
            'SessionDelegationService must stay Bot-agnostic (Bot → Generator is one-way).',
        );
    }

    private function sourceOf(string $fqcn): string
    {
        $file = (new ReflectionClass($fqcn))->getFileName();
        $this->assertIsString($file);

        return (string) file_get_contents((string) $file);
    }
}
