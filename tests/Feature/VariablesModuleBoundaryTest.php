<?php

namespace Tests\Feature;

use App\Modules\Variables\Contracts\AiTextGenerator;
use App\Modules\Variables\Services\PipelineValidator;
use App\Modules\Variables\Services\VariableCatalog;
use App\Modules\Variables\Services\VariableResolver;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pins for the Variables module boundary (flagged during the Phase 1 engine
 * extraction, re-asserted at the R2 PR-1a down-move). Variables is the LOWER layer: Workflows (and,
 * R2, Generator) depend on Variables, and Variables must import NOTHING from Workflows — a one-way
 * dependency. And the two whitelists that MUST agree (the validator's reference sources and the
 * resolver's context roots) are asserted equal so they can never silently drift apart across the split.
 */
class VariablesModuleBoundaryTest extends TestCase
{
    /**
     * No file under app/modules/Variables may name a SIBLING module's class: Variables is the LOWEST
     * shared layer, so the dependency direction is strictly (Workflows | Disk | Generator) → Variables.
     * A back-reference would create a cycle and couple Variables to one of its consumers. This scan
     * covers the R2 PR-1a arrivals too (the relocated resolver, the shared catalog, the ai-text
     * contract + generator + cost meter), since they live under this root; it also pins the Disk and
     * Generator edges — currently clean — so a future stray import fails loudly.
     */
    public function test_variables_module_imports_no_sibling_module(): void
    {
        $root = app_path('modules/Variables');
        $this->assertDirectoryExists($root);

        // Every module that DEPENDS ON Variables — none may be named from inside it (the one-way edge).
        $forbidden = ['App\\Modules\\Workflows', 'App\\Modules\\Disk', 'App\\Modules\\Generator'];

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
            $source = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $sibling) {
                $this->assertStringNotContainsString(
                    $sibling,
                    $source,
                    $file->getPathname() . ' must not depend on ' . $sibling . ' (Variables is the lowest shared layer).',
                );
            }
        }

        $this->assertGreaterThan(0, $scanned, 'expected to scan the Variables module source files');
    }

    /**
     * The SHARED authoring surface promoted DOWN in R2 PR-1a — the interpolation resolver, the
     * form-independent catalog composition, and the ai-text generation seam — must be Variables
     * classes now (so both Workflows and the coming Generator depend on them one-way). Each must
     * resolve under App\Modules\Variables and, like every Variables file, name no Workflows class.
     */
    public function test_the_relocated_shared_surface_lives_in_variables(): void
    {
        foreach ([VariableResolver::class, VariableCatalog::class, AiTextGenerator::class] as $fqcn) {
            $this->assertStringStartsWith('App\\Modules\\Variables\\', $fqcn, $fqcn . ' must live in the Variables module.');

            $file = (new ReflectionClass($fqcn))->getFileName();
            $this->assertIsString($file, $fqcn . ' must be a real file-backed class.');
            $this->assertStringNotContainsString(
                'App\\Modules\\Workflows',
                (string) file_get_contents((string) $file),
                $fqcn . ' (relocated into Variables) must not depend on the Workflows module.',
            );
        }
    }

    /**
     * The pipeline validator's DEFAULT reference sources and the resolver's context ROOTS are the same
     * whitelist (`trigger`, `steps`, `globals`, `slots`) living on both sides of the module split. If
     * one changes without the other, a reference the validator accepts could be unresolvable at runtime
     * (or vice versa), so this pins them byte-identical. The R2 superset adds the template `slots` root
     * (inert for workflows), so both sides must carry it.
     */
    public function test_pipeline_validator_reference_sources_match_the_resolver_roots(): void
    {
        $defaultSources = (new ReflectionClass(PipelineValidator::class))
            ->getConstant('DEFAULT_REFERENCE_SOURCES');

        $this->assertSame(VariableResolver::ROOTS, $defaultSources);
        $this->assertContains('globals', $defaultSources, 'the globals wire root must be whitelisted');
        $this->assertContains('slots', $defaultSources, 'the slots template root joins the superset');
    }
}
