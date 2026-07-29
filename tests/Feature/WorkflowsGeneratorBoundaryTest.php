<?php

namespace Tests\Feature;

use App\Modules\Workflows\Http\Requests\StoreWorkflowRequest;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Listeners\ResumeWaitingRunOnSessionTerminal;
use App\Modules\Workflows\Services\GenerationSessionWaitResolver;
use App\Modules\Workflows\Services\WaitResolverRegistry;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Steps\GenerateContentStep;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pin for the Workflows → Generator edge (R2 sub-stage 5), mirroring
 * {@see BotModuleBoundaryTest}. The `generate_content` step is the ONE new cross-module edge: Workflows
 * CALLS the Generator's HTTP-free automation seams, and the Generator NEVER calls back. Both halves are
 * locked here so the direction can never silently invert.
 *
 * {@see GeneratorModuleBoundaryTest} stays green UNCHANGED — that is the point: nothing was added to the
 * Generator that names Workflows, so its own boundary assertion did not have to move.
 */
class WorkflowsGeneratorBoundaryTest extends TestCase
{
    /**
     * The three Workflows classes that own the edge DO name Generator — the positive half. They reuse the
     * Generator's automation/collect seams rather than reaching into its models or forking its logic.
     */
    public function test_the_generate_content_seam_classes_depend_on_generator(): void
    {
        foreach ([
            GenerateContentStep::class,
            GenerationSessionWaitResolver::class,
            ResumeWaitingRunOnSessionTerminal::class,
            StoreWorkflowRequest::class,
        ] as $fqcn) {
            $this->assertStringContainsString(
                'App\\Modules\\Generator',
                $this->sourceOf($fqcn),
                $fqcn . ' must call the Generator seams (Workflows → Generator is the deliberate edge).',
            );
        }
    }

    /**
     * The other half of the positive edge: the ENGINE CORE stays Generator-FREE. The step contract is what
     * carries the dependency — the runner, the run manager, the two jobs and the wait registry must keep
     * speaking only in generic steps/wait kinds, so a second waiting integration (or removing the Generator
     * one) never means touching the engine. Without this, a "quick fix" inside the runner naming a
     * GenerationSession would pass every other test in the suite.
     */
    public function test_the_workflow_engine_core_never_names_generator(): void
    {
        foreach ([
            WorkflowStepRunner::class,
            WorkflowRunManager::class,
            WorkflowRunJob::class,
            WorkflowRunResumeJob::class,
            WaitResolverRegistry::class,
        ] as $fqcn) {
            $this->assertStringNotContainsString(
                'App\\Modules\\Generator',
                $this->sourceOf($fqcn),
                $fqcn . ' is engine CORE — it must stay generic (the step/resolver own the Generator edge).',
            );
        }
    }

    /**
     * The reverse is FORBIDDEN, module-wide: NO file under app/modules/Generator may name a Workflows class.
     * The Generator exposes primitives-only seams and its own terminal EVENT; Workflows is what reacts. A
     * back-reference would make the two peers cyclic and would break the one-way posture
     * GeneratorModuleBoundaryTest also pins.
     */
    public function test_no_generator_file_ever_names_workflows(): void
    {
        $root = app_path('modules/Generator');
        $this->assertDirectoryExists($root);

        $scanned = 0;

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;

            $this->assertStringNotContainsString(
                'App\\Modules\\Workflows',
                (string) file_get_contents($file->getPathname()),
                $file->getPathname() . ' must not depend on Workflows (Workflows → Generator is one-way).',
            );
        }

        $this->assertGreaterThan(0, $scanned, 'expected to scan the Generator module source files');
    }

    /**
     * The wait KIND is one string shared by the step, the resolver and the listener — built through the
     * step's own helper so a rename can never desynchronize the park key from the resolver's registry key
     * or from what the listener looks a parked run up by.
     */
    public function test_the_wait_kind_and_correlation_key_have_a_single_source(): void
    {
        $this->assertSame('generation_session', GenerateContentStep::WAIT_KIND);
        $this->assertSame('generation_session:abc', GenerateContentStep::correlationKey('abc'));
        $this->assertSame(GenerateContentStep::WAIT_KIND, app(GenerationSessionWaitResolver::class)->kind());
    }

    /**
     * The registration is LAZY, and that is load-bearing for the edge: eagerly constructing the resolver in
     * the provider's boot() would drag the Generator's SessionAutomationService + GenerationSessionService
     * into EVERY request of the application, including every request that has nothing to do with workflows.
     * Only the waiting-run sweep actually asks, so only the sweep pays.
     */
    public function test_the_generation_session_resolver_is_registered_lazily(): void
    {
        $this->assertFalse(
            app()->resolved(GenerationSessionWaitResolver::class),
            'booting the app must not construct the Generator-backed wait resolver',
        );

        $registry = app(WaitResolverRegistry::class);
        $this->assertSame([GenerateContentStep::WAIT_KIND], $registry->kinds(), 'the KIND is known without constructing anything');

        $resolver = $registry->for(GenerateContentStep::WAIT_KIND);

        $this->assertInstanceOf(GenerationSessionWaitResolver::class, $resolver);
        $this->assertSame($resolver, $registry->for(GenerateContentStep::WAIT_KIND), 'resolved once, then memoized');
    }

    private function sourceOf(string $fqcn): string
    {
        $file = (new ReflectionClass($fqcn))->getFileName();
        $this->assertIsString($file);

        return (string) file_get_contents((string) $file);
    }
}
