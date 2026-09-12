<?php

namespace Tests\Feature;

use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Listeners\ResumeWaitingRunOnPublicationConcluded;
use App\Modules\Workflows\Services\PublicationWaitResolver;
use App\Modules\Workflows\Services\WaitResolverRegistry;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Steps\PublishStep;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pin for the Workflows → Publishing edge (R4 B6), mirroring
 * {@see WorkflowsGeneratorBoundaryTest}. The `publish` step is the ONE new cross-module edge:
 * Workflows CALLS the Publishing module's HTTP-free automation seam, and Publishing NEVER calls back —
 * it announces a conclusion as its own event and Workflows is what reacts.
 *
 * A third direction is pinned here too, because B6 created it: Publishing now implements the Approvals
 * module's `Approvable` contract, which means Approvals ← Publishing already exists as an edge — and
 * Approvals must stay a SHARED ENGINE that knows none of its clients. The day `ApprovalService` grows an
 * `instanceof Publication`, the second Approvable has taught the engine one module's name, and the third
 * entity gets an `elseif`.
 *
 * Every assertion below was run as a MUTATION before it was trusted: a forbidden import added to
 * `PublicationManager` (Publishing→Workflows) and to `ApprovalService` (Approvals→Publishing) each left
 * the whole suite green until this file existed. That is why it reads bytes, not behaviour.
 */
class WorkflowsPublishingBoundaryTest extends TestCase
{
    /**
     * The three Workflows classes that own the edge DO name Publishing — the positive half. They speak
     * to the module's automation seam and its conclusion event, never to its models' internals.
     */
    public function test_the_publish_seam_classes_depend_on_publishing(): void
    {
        foreach ([
            PublishStep::class,
            PublicationWaitResolver::class,
            ResumeWaitingRunOnPublicationConcluded::class,
        ] as $fqcn) {
            $this->assertStringContainsString(
                'App\\Modules\\Publishing',
                $this->sourceOf($fqcn),
                $fqcn . ' must call the Publishing seams (Workflows → Publishing is the deliberate edge).',
            );
        }
    }

    /**
     * The ENGINE CORE stays Publishing-free. The step contract carries the dependency — the runner, the
     * run manager, the two jobs and the wait registry keep speaking only in generic steps and wait
     * kinds, so removing the Publishing integration (or adding a third waiting one) never means
     * touching the engine.
     */
    public function test_the_workflow_engine_core_never_names_publishing(): void
    {
        foreach ([
            WorkflowStepRunner::class,
            WorkflowRunManager::class,
            WorkflowRunJob::class,
            WorkflowRunResumeJob::class,
            WaitResolverRegistry::class,
        ] as $fqcn) {
            $this->assertStringNotContainsString(
                'App\\Modules\\Publishing',
                $this->sourceOf($fqcn),
                $fqcn . ' is engine CORE — it must stay generic (the step/resolver own the Publishing edge).',
            );
        }
    }

    /**
     * The reverse is FORBIDDEN, module-wide: NO file under app/modules/Publishing may name a Workflows
     * class. The temptation this pin exists for is concrete: a "lost resume" bug report, and somebody
     * making `PublicationManager` look the waiting `WorkflowRun` up and set it running — the cycle
     * closes, nothing else in the suite notices. Publishing announces `PublicationConcluded`; what wakes
     * on it is not Publishing's business.
     */
    public function test_no_publishing_file_ever_names_workflows(): void
    {
        $this->assertModuleNeverNames('Publishing', 'App\\Modules\\Workflows');
    }

    /**
     * And the engine of record for reviews stays client-blind: NO file under app/modules/Approvals may
     * name Publishing. `Publication implements Approvable` makes Publishing the SECOND client of that
     * shared engine — the contract is the whole interface between them, in one direction.
     */
    public function test_no_approvals_file_ever_names_publishing(): void
    {
        $this->assertModuleNeverNames('Approvals', 'App\\Modules\\Publishing');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE STEP CANNOT PUBLISH, AND THIS IS THE ASSERTION THAT SAYS SO WHATEVER A TEST DRIVES.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * Every behavioural test observes the paths it happens to drive. This one reads the bytes: the step
     * and the module's automation seam may not IMPORT the publisher, may not name its claimed-publish
     * entry point, may not make a call whose method name is `publish` — and may not USE the publishing
     * job either, because `PublishPublicationJob::dispatchSync($id, …)` is the same escape wearing queue
     * clothing: it skips the due-sweep's atomic claim exactly as thoroughly as a direct publish call.
     * (The job's NAME in prose stays legal — the seam's docblock explains why the lock matters by naming
     * it — so the patterns below match usage, not mention.)
     *
     * Why pinning beats reviewing: a synchronous publish from a step would bypass the atomic claim
     * (ADR-0055 Decision 4), bypass the per-publication overlap lock, and run the module's deliberately
     * transaction-free two-phase sequence inside a run job that forces the `sync` queue driver. Each of
     * those reads as a small convenience in a diff. The sum is a post that exists twice.
     */
    public function test_neither_the_step_nor_the_seam_can_reach_the_publisher(): void
    {
        foreach ([
            app_path('modules/Workflows/Steps/PublishStep.php'),
            app_path('modules/Publishing/Services/PublicationAutomationService.php'),
        ] as $path) {
            $this->assertFileExists($path);

            // COMMENTS ARE STRIPPED FIRST, and that is what lets the needles be BARE IDENTIFIERS.
            // The first version banned three spellings of using the job — and an aliased import
            // (`use …\PublishPublicationJob as ImmediateDelivery;`) sailed past all of them, verified
            // as a mutation during the B6 re-review. Code cannot alias its way around a ban on the
            // identifier itself; prose in a docblock may keep naming the job (the seam's own docblock
            // explains the overlap lock BY naming it), because prose is not in the stripped source.
            $source = $this->withoutComments((string) file_get_contents($path));

            foreach (['PublicationPublisher', 'PublishPublicationJob', 'publishClaimed'] as $identifier) {
                $this->assertStringNotContainsString(
                    $identifier,
                    $source,
                    $path . ' reaches ' . $identifier . ' in CODE. An automated publish must go through '
                    . 'the due-sweep\'s atomic claim — there is no spelling of skipping it that is fine.',
                );
            }

            $this->assertSame(
                0,
                preg_match('/->\s*publish\s*\(/', $source),
                $path . ' calls publish(). The step ARMS; the sweep publishes.',
            );
        }
    }

    /**
     * AND APPROVALS CANNOT COMPARE ITS WAY TO A CLIENT EITHER. The namespace scan above refuses
     * `App\Modules\Publishing`; this one refuses the semantic route around it — `class_basename($x) ===
     * 'Publication'`, `getMorphClass() === 'publication'` — which a re-review mutation walked straight
     * through while the namespace scan stayed green. A quoted client name in the engine's CODE is
     * knowledge of a client whatever the comparison; today there are zero, and it must stay zero.
     */
    public function test_approvals_never_compares_its_way_to_a_client(): void
    {
        $root = app_path('modules/Approvals');
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

            $source = $this->withoutComments((string) file_get_contents($file->getPathname()));

            foreach (["'publication'", "'Publication'", '"publication"', '"Publication"'] as $literal) {
                $this->assertStringNotContainsString(
                    $literal,
                    $source,
                    $file->getPathname() . ' names a client by literal ' . $literal . ' — the engine '
                    . 'stays client-blind; the day it grows a comparison, the third approvable gets an elseif.',
                );
            }
        }

        $this->assertGreaterThan(0, $scanned, 'expected to scan the Approvals module source files');
    }

    /**
     * The registration is LAZY, and that is load-bearing for the edge: eagerly constructing the resolver
     * in the provider's boot() would drag `PublicationAutomationService` and its dependencies into EVERY
     * request of the application. Only the waiting-run sweep actually asks, so only the sweep pays.
     */
    public function test_the_publication_resolver_is_registered_lazily(): void
    {
        $this->assertFalse(
            app()->resolved(PublicationWaitResolver::class),
            'booting the app must not construct the Publishing-backed wait resolver',
        );

        $registry = app(WaitResolverRegistry::class);

        $this->assertContains(PublishStep::WAIT_KIND, $registry->kinds(), 'the KIND is known without constructing anything');

        $resolver = $registry->for(PublishStep::WAIT_KIND);

        $this->assertInstanceOf(PublicationWaitResolver::class, $resolver);
        $this->assertSame($resolver, $registry->for(PublishStep::WAIT_KIND), 'resolved once, then memoized');
    }

    /** Byte-scan one module's whole source tree for a namespace it must never name. */
    private function assertModuleNeverNames(string $module, string $forbidden): void
    {
        $root = app_path('modules/' . $module);
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
                $forbidden,
                (string) file_get_contents($file->getPathname()),
                $file->getPathname() . ' must not depend on ' . $forbidden . ' (the edge is one-way).',
            );
        }

        $this->assertGreaterThan(0, $scanned, 'expected to scan the ' . $module . ' module source files');
    }

    private function sourceOf(string $fqcn): string
    {
        $file = (new ReflectionClass($fqcn))->getFileName();
        $this->assertIsString($file);

        return (string) file_get_contents((string) $file);
    }

    /** The source with comments and docblocks removed — so code-needles never burn on prose. */
    private function withoutComments(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }
}
