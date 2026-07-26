<?php

namespace Tests\Feature;

use App\Modules\Variables\Services\PipelineValidator;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pins for the Variables module boundary (flagged during the Phase 1 engine
 * extraction). Variables is the LOWER layer: Workflows depends on Variables, and Variables must
 * import NOTHING from Workflows — a one-way dependency. And the two whitelists that MUST agree (the
 * validator's reference sources and the resolver's context roots) are asserted equal so they can
 * never silently drift apart across the module split.
 */
class VariablesModuleBoundaryTest extends TestCase
{
    /**
     * No file under app/modules/Variables may name a Workflows class: the dependency direction is
     * strictly Workflows → Variables. A back-reference would create a cycle and break the extraction.
     */
    public function test_variables_module_imports_nothing_from_workflows(): void
    {
        $root = app_path('modules/Variables');
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
                $file->getPathname() . ' must not depend on the Workflows module (Variables is the lower layer).',
            );
        }

        $this->assertGreaterThan(0, $scanned, 'expected to scan the Variables module source files');
    }

    /**
     * The pipeline validator's DEFAULT reference sources and the resolver's context ROOTS are the
     * same whitelist (`trigger`, `steps`, `globals`) living on both sides of the module split. If one
     * changes without the other, a reference the validator accepts could be unresolvable at runtime
     * (or vice versa), so this pins them byte-identical.
     */
    public function test_pipeline_validator_reference_sources_match_the_resolver_roots(): void
    {
        $defaultSources = (new ReflectionClass(PipelineValidator::class))
            ->getConstant('DEFAULT_REFERENCE_SOURCES');

        $this->assertSame(WorkflowVariableResolver::ROOTS, $defaultSources);
        $this->assertContains('globals', $defaultSources, 'the globals wire root must be whitelisted');
    }
}
