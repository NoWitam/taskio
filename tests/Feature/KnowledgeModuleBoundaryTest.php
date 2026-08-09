<?php

namespace Tests\Feature;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\KnowledgeModuleServiceProvider;
use App\Modules\Variables\VariablesModuleServiceProvider;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Architectural pin for the Knowledge module boundary (mirrors VariablesModuleBoundaryTest, which is
 * the template for every low-layer module in this codebase).
 *
 * Knowledge is a LOW layer sitting beside Variables: it may depend on the Variables module and on
 * App\Support, and it must import NOTHING from the modules that CONSUME it. A knowledge base is only
 * worth building once if MANY unrelated consumers can read it, and the moment Knowledge names one of
 * them that consumer becomes a dependency of the shared layer — which both creates a cycle and makes
 * the base un-reusable by everything else. So the dependency direction is strictly
 * (consumer) -> Knowledge, never back.
 *
 * The scan is LITERAL over the file bytes, so it also catches a forbidden class named only in a
 * comment or docblock. That is on purpose: a docblock reference is exactly how a real import starts,
 * and it is the form a reviewer is most likely to wave through.
 */
class KnowledgeModuleBoundaryTest extends TestCase
{
    /**
     * No file under app/modules/Knowledge may name a CONSUMER module's class. The forbidden list is
     * every module that may come to read the knowledge base; none of them may be named from inside it.
     */
    public function test_knowledge_module_imports_no_consumer_module(): void
    {
        $root = app_path('modules/Knowledge');
        $this->assertDirectoryExists($root);

        $forbidden = [
            'App\\Modules\\Workflows',
            'App\\Modules\\Generator',
            'App\\Modules\\Bot',
            'App\\Modules\\Disk',
            'App\\Modules\\Forms',
            'App\\Modules\\Approvals',
            'App\\Modules\\Tasks',
        ];

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

            foreach ($forbidden as $consumer) {
                $this->assertStringNotContainsString(
                    $consumer,
                    $source,
                    $file->getPathname() . ' must not depend on ' . $consumer . ' (Knowledge is a lower shared layer).',
                );
            }
        }

        // Guards the scan itself: a module that was moved, renamed or emptied would otherwise make
        // this test pass vacuously forever.
        $this->assertGreaterThan(0, $scanned, 'expected to scan the Knowledge module source files');
    }

    /**
     * The provider file ORDER mirrors the dependency direction: Knowledge is registered after
     * Variables, the layer it is allowed to depend on.
     *
     * That is the whole claim, and it is deliberately narrower than it used to be. An earlier version
     * of this test also asserted Knowledge boots "before the consumers that will read it", which was
     * simply FALSE — the Bot provider sits above it in bootstrap/providers.php and always has. The
     * assertion passed anyway because it never checked that half, which is the worst kind of pin: a
     * sentence a reader trusts, guarding nothing.
     *
     * Consumer order is not pinned because it CANNOT matter here. Knowledge exposes plain services
     * resolved from the container on demand; it registers no contract-plus-default pair, so there is no
     * `bindIf` race for a consumer to win or lose by registering first. (Contrast the author-voice and
     * session-author seams, where a default IS shipped alongside a concrete — those need explicit
     * order-independence pins, and BotModuleBoundaryTest gives them one each.) The second assertion
     * below states that positively: a consumer registers earlier, and the module's seams still resolve.
     */
    public function test_knowledge_provider_is_registered_after_variables(): void
    {
        /** @var list<class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        $variables = array_search(VariablesModuleServiceProvider::class, $providers, true);
        $knowledge = array_search(KnowledgeModuleServiceProvider::class, $providers, true);

        $this->assertIsInt($variables, 'the Variables provider must be registered');
        $this->assertIsInt($knowledge, 'the Knowledge provider must be registered in bootstrap/providers.php');
        $this->assertGreaterThan($variables, $knowledge, 'Knowledge must boot after the Variables layer it depends on');
    }

    /**
     * A CONSUMER registering before Knowledge is harmless — asserted rather than assumed, because the
     * previous version of this file assumed the opposite and was wrong.
     *
     * Both seams this module binds resolve from the fully-booted container, with the Bot provider
     * registered ahead of it exactly as production does. If Knowledge ever grows a default that a
     * consumer could clobber, this is where that assumption breaks.
     */
    public function test_the_knowledge_seams_resolve_even_though_a_consumer_registers_first(): void
    {
        /** @var list<class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        $this->assertLessThan(
            array_search(KnowledgeModuleServiceProvider::class, $providers, true),
            array_search(\App\Modules\Bot\BotModuleServiceProvider::class, $providers, true),
            'this test is only meaningful while a consumer really does register first',
        );

        $this->assertInstanceOf(KnowledgeEmbedder::class, app(KnowledgeEmbedder::class));
        $this->assertInstanceOf(KnowledgeSimilaritySearch::class, app(KnowledgeSimilaritySearch::class));
    }
}
