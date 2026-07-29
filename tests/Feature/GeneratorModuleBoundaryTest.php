<?php

namespace Tests\Feature;

use App\Modules\Generator\Services\ImageBaseResolver;
use App\Modules\Generator\Services\ImageChainExecutor;
use App\Modules\Generator\Services\TemplateRenderService;
use App\Modules\Generator\Services\TemplateVariableCatalog;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pin for the Generator module boundary (mirrors VariablesModuleBoundaryTest). Generator
 * CONSUMES Variables — the type system, the shared catalog composition, and the shared interpolation
 * engine — but must import NOTHING from Workflows: the dependency direction is strictly
 * Generator → Variables (never Workflows), so the two upper modules stay siblings that share only the
 * lower Variables layer. A back-reference to Workflows would couple two peer modules and break that.
 *
 * R2 sub-stage 2c ADDS a deliberate, documented Generator → Disk edge (Fork 3): the image chain resolves a
 * base from a Disk file, runs `ai_edit` through Disk's ImageAiService, and saves a produced image through
 * Disk's FileService. So Disk is now an ALLOWED dependency; Workflows remains forbidden.
 *
 * R2 sub-stage 3 (bots in the generator) adds a one-way Bot → Generator edge (delegation): the Generator
 * exposes the delegation seams (SessionDelegationService takes primitives/opaque strings), and the BOT
 * module calls them — so Generator must import NOTHING from Bot. Bot joins Workflows as forbidden here.
 */
class GeneratorModuleBoundaryTest extends TestCase
{
    /**
     * No file under app/modules/Generator may name a Workflows OR a Bot class — both are PEER/upper modules
     * (Generator → Variables/Disk only). The Bot → Generator delegation edge is strictly one-way; a
     * back-reference to Bot would create a cycle and couple the two.
     */
    public function test_generator_module_imports_nothing_from_workflows_or_bot(): void
    {
        $root = app_path('modules/Generator');
        $this->assertDirectoryExists($root);

        $forbidden = ['App\\Modules\\Workflows', 'App\\Modules\\Bot'];
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
                    $file->getPathname() . ' must not depend on ' . $sibling . ' (Generator → Variables/Disk only; Bot → Generator is one-way).',
                );
            }
        }

        $this->assertGreaterThan(0, $scanned, 'expected to scan the Generator module source files');
    }

    /**
     * The Generator services that touch the shared engine DO depend on Variables (and name no Workflows
     * class) — the positive half of the one-way boundary: Generator reuses the Variables surface rather
     * than forking it or reaching into Workflows.
     */
    public function test_generator_services_depend_on_variables_not_workflows(): void
    {
        foreach ([TemplateVariableCatalog::class, TemplateRenderService::class] as $fqcn) {
            $file = (new ReflectionClass($fqcn))->getFileName();
            $this->assertIsString($file);

            $source = (string) file_get_contents((string) $file);
            $this->assertStringContainsString('App\\Modules\\Variables', $source, $fqcn . ' must reuse the Variables layer.');
            $this->assertStringNotContainsString('App\\Modules\\Workflows', $source, $fqcn . ' must not depend on Workflows.');
        }
    }

    /**
     * The R2 sub-stage 2c image services DO reach into Disk — the deliberate, documented Generator → Disk
     * edge (Fork 3: base resolve reads a Disk File; the chain runs Disk's ImageAiService). They still name no
     * Workflows class, so the peer boundary holds even as the Disk edge is added.
     */
    public function test_generator_image_services_may_depend_on_disk_but_never_workflows(): void
    {
        foreach ([ImageBaseResolver::class, ImageChainExecutor::class] as $fqcn) {
            $file = (new ReflectionClass($fqcn))->getFileName();
            $this->assertIsString($file);

            $source = (string) file_get_contents((string) $file);
            $this->assertStringContainsString('App\\Modules\\Disk', $source, $fqcn . ' is expected to use the allowed Disk edge.');
            $this->assertStringNotContainsString('App\\Modules\\Workflows', $source, $fqcn . ' must not depend on Workflows.');
        }
    }
}
