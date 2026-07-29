<?php

namespace Tests\Unit\Variables;

use Tests\TestCase;

/**
 * THE COMPLETENESS GATE (Phase 3b, reviewer-gated): every op resolution in the pipeline ENGINE must go
 * through the OperationResolver (built-ins ∪ custom functions), so a `fn:<uuid>` step resolves everywhere.
 * A direct `Operation::tryFrom(...)` anywhere in the engine would be a site a function silently fails to
 * resolve at — a latent fail-open / mis-run. This test PINS the grep-zero proof as a regression guard: the
 * ONLY place `Operation::tryFrom(` may appear is inside the OperationResolver itself (the one indirection).
 */
class EngineOperationResolverCompletenessTest extends TestCase
{
    /**
     * The engine files whose op resolution must funnel through the OperationResolver.
     *
     * @return array<int, string>
     */
    private function engineFiles(): array
    {
        return [
            'app/modules/Variables/Services/OperationExecutor.php',
            'app/modules/Variables/Services/PipelineValidator.php',
            'app/modules/Variables/Services/VariableResolver.php',
        ];
    }

    public function test_no_engine_file_calls_operation_tryfrom_directly(): void
    {
        foreach ($this->engineFiles() as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertIsString($source, "missing engine file {$file}");
            // A real call is `Operation::tryFrom(` (no space — Pint style); prose like "…tryFrom (the gate)"
            // is deliberately NOT matched, so a doc-comment mentioning the method never trips the guard.
            $this->assertDoesNotMatchRegularExpression(
                '/Operation::tryFrom\(/',
                $source,
                "{$file} must resolve ops through OperationResolver, never a direct Operation::tryFrom().",
            );
        }
    }

    public function test_the_operation_resolver_is_the_one_place_tryfrom_lives(): void
    {
        // The single sanctioned Operation::tryFrom — the built-in half of the resolver. If this ever stops
        // matching, the resolver was refactored and the guard above should be re-pointed accordingly.
        $resolver = file_get_contents(base_path('app/modules/Variables/Services/OperationResolver.php'));

        $this->assertMatchesRegularExpression('/Operation::tryFrom\(/', $resolver);
    }
}
