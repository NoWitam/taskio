<?php

namespace Tests\Feature;

use App\Modules\Bot\BotModuleServiceProvider;
use App\Modules\Bot\Http\Controllers\BotSessionDelegationController;
use App\Modules\Bot\Jobs\GenerateBotVisualJob;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotAuthorVoiceResolver;
use App\Modules\Bot\Services\BotKnowledgeReader;
use App\Modules\Bot\Services\BotKnowledgeService;
use App\Modules\Bot\Services\BotSessionIdentityResolver;
use App\Modules\Bot\Services\BotSlotFillService;
use App\Modules\Bot\Services\BotVisualIdentityService;
use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;
use App\Modules\Generator\GeneratorModuleServiceProvider;
use App\Modules\Generator\Services\SessionDelegationService;
use App\Modules\Generator\Support\NullSessionAuthorIdentityResolver;
use App\Modules\Knowledge\Services\KnowledgeBindingService;
use App\Modules\Knowledge\Services\KnowledgeCompiler;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Variables\Contracts\AuthorVoiceResolver;
use App\Modules\Variables\Support\NullAuthorVoiceResolver;
use App\Modules\Variables\VariablesModuleServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural pin for the Bot → Generator delegation edge (R2 sub-stage 3). Delegation is the ONE new
 * cross-module edge: the Bot module CALLS the Generator's delegation seams + the Variables ai-text seam,
 * and the Generator NEVER calls back (SessionDelegationService takes primitives/opaque strings/its own
 * model, never a Bot class). This test locks BOTH halves so the direction can never silently invert.
 *
 * The BOT-IN-A-WORKFLOW-STEP gate adds a second, INVERTED crossing of the same edge: a peer module
 * (Workflows) that may name neither side orders an author through a Generator CONTRACT, which this module
 * binds the concrete for. Its two halves — the contract/concrete split and the provider-order independence —
 * are pinned alongside the author-voice ones they mirror.
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
     * The per-block AUTHOR seam is the same one-way edge in its purest form: Variables owns the
     * AuthorVoiceResolver CONTRACT (and must never name Bot — pinned by VariablesModuleBoundaryTest),
     * while the BOT module owns the concrete that knows an author IS a bot. So the resolver must name
     * BOTH sides: its own module's Bot model/composer and the Variables contract it implements.
     */
    public function test_the_author_voice_resolver_implements_the_variables_contract_over_bots(): void
    {
        $resolver = $this->sourceOf(BotAuthorVoiceResolver::class);

        $this->assertStringContainsString('App\\Modules\\Bot', $resolver, 'the author-voice resolver must resolve BOTS.');
        $this->assertStringContainsString('App\\Modules\\Variables', $resolver, 'the author-voice resolver must implement the Variables contract.');
        $this->assertInstanceOf(
            AuthorVoiceResolver::class,
            app(BotAuthorVoiceResolver::class),
            'the Bot resolver must satisfy the Variables AuthorVoiceResolver contract.',
        );
        $this->assertInstanceOf(
            BotAuthorVoiceResolver::class,
            app(AuthorVoiceResolver::class),
            'the Bot binding must WIN over the Variables default null object (bindIf + provider order).',
        );
    }

    /**
     * ORDER-INDEPENDENCE PIN for the two-provider seam. It is split across TWO providers — Variables ships
     * the default, Bot binds the concrete — so "which one registers last" decides which implementation the
     * whole per-block-author feature runs on. Getting it wrong is FAIL-SAFE and therefore INVISIBLE: nothing
     * breaks, no run fails, every authored block just quietly stops sounding authored.
     *
     * Two independent guarantees make the winner NOT depend on the order at all, and this pins both halves
     * by REGISTERING each provider LAST over the live container:
     *   (a) Variables uses bindIf, so its default cannot clobber an already-bound concrete;
     *   (b) Bot uses an unconditional bind, so the concrete wins over an already-installed default.
     *
     * Both halves are needed: dropping either one turns the file order in bootstrap/providers.php into a
     * silent, load-bearing dependency (and it is deliberately NOT asserted here, so a harmless reorder stays
     * harmless).
     */
    public function test_the_author_seam_resolves_to_the_bot_concrete_whichever_provider_registers_last(): void
    {
        // (a) VARIABLES LAST — over the concrete the real boot already bound.
        (new VariablesModuleServiceProvider($this->app))->register();

        $this->assertInstanceOf(
            BotAuthorVoiceResolver::class,
            $this->app->make(AuthorVoiceResolver::class),
            'the Variables DEFAULT must never clobber the Bot concrete — bindIf, not bind.',
        );

        // (b) BOT LAST — over an already-installed null object, i.e. the provider order flipped.
        $this->app->bind(AuthorVoiceResolver::class, NullAuthorVoiceResolver::class);
        (new BotModuleServiceProvider($this->app))->register();

        $this->assertInstanceOf(
            BotAuthorVoiceResolver::class,
            $this->app->make(AuthorVoiceResolver::class),
            'the Bot concrete must WIN over an already-bound default — bind, not bindIf.',
        );
    }

    /**
     * The SESSION-AUTHOR seam is the same inversion one layer up (a workflow's `generate_content` step
     * delegating its generation session to a bot): the GENERATOR owns the contract — and must never name Bot
     * (pinned module-wide by GeneratorModuleBoundaryTest) — while the BOT module owns the concrete that
     * knows an author IS a bot. So the resolver must name BOTH sides.
     *
     * It must ALSO reuse the same composition the interactive delegation uses. That is asserted rather than
     * merely hoped for, because a forked composition is the one failure this feature could have that nothing
     * would ever fail on: an automated post would simply be subtly less "in voice" than a hand-delegated
     * one, forever, with every test green.
     */
    public function test_the_session_identity_resolver_implements_the_generator_contract_over_bots(): void
    {
        $resolver = $this->sourceOf(BotSessionIdentityResolver::class);

        $this->assertStringContainsString('App\\Modules\\Bot', $resolver, 'the session-identity resolver must resolve BOTS.');
        $this->assertStringContainsString('App\\Modules\\Generator', $resolver, 'the session-identity resolver must implement the Generator contract.');
        $this->assertStringContainsString(
            'BotDelegationIdentityComposer',
            $resolver,
            'the automated delegation must reuse the SHARED identity composition, never a fork of it.',
        );
        $this->assertStringContainsString(
            'BotDelegationIdentityComposer',
            $this->sourceOf(BotSessionDelegationController::class),
            'the interactive delegation must reuse the SAME composition.',
        );

        $this->assertInstanceOf(
            SessionAuthorIdentityResolver::class,
            app(BotSessionIdentityResolver::class),
            'the Bot resolver must satisfy the Generator SessionAuthorIdentityResolver contract.',
        );
        $this->assertInstanceOf(
            BotSessionIdentityResolver::class,
            app(SessionAuthorIdentityResolver::class),
            'the Bot binding must WIN over the Generator default null object (bindIf + provider order).',
        );
    }

    /**
     * ORDER-INDEPENDENCE PIN for the SESSION-AUTHOR seam — the same two-provider deal as the author-voice
     * one above, and it needs the same protection for a sharper reason: getting it wrong here is NOT
     * fail-safe. The Generator default resolves NOTHING, so a provider order that let it win would make every
     * bot-authored `generate_content` step REFUSE to run — a whole feature dead, workspace-wide, from a file
     * reorder nobody would connect to it.
     *
     * Both halves are pinned by REGISTERING each provider LAST over the live container:
     *   (a) Generator uses bindIf, so its default cannot clobber an already-bound concrete (with TODAY's
     *       order — Bot registers first — this is the half actually doing the work);
     *   (b) Bot uses an unconditional bind, so the concrete wins over an already-installed default.
     */
    public function test_the_session_author_seam_resolves_to_the_bot_concrete_whichever_provider_registers_last(): void
    {
        // (a) GENERATOR LAST — over the concrete the real boot already bound.
        (new GeneratorModuleServiceProvider($this->app))->register();

        $this->assertInstanceOf(
            BotSessionIdentityResolver::class,
            $this->app->make(SessionAuthorIdentityResolver::class),
            'the Generator DEFAULT must never clobber the Bot concrete — bindIf, not bind.',
        );

        // (b) BOT LAST — over an already-installed null object, i.e. the provider order flipped.
        $this->app->bind(SessionAuthorIdentityResolver::class, NullSessionAuthorIdentityResolver::class);
        (new BotModuleServiceProvider($this->app))->register();

        $this->assertInstanceOf(
            BotSessionIdentityResolver::class,
            $this->app->make(SessionAuthorIdentityResolver::class),
            'the Bot concrete must WIN over an already-bound default — bind, not bindIf.',
        );
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

    /**
     * The bot's VISUAL identity is the second DELIBERATE Bot → Disk edge (B2): creating a likeness reuses the
     * Disk's async image machinery — the same status row, daily cap, cost-meter gate, poll and broadcast — and
     * files the result as a Disk file the bot owns. Forking a second image pipeline into the Bot module is
     * exactly what this pins against, so the dependency is asserted rather than merely tolerated.
     */
    public function test_the_visual_identity_seam_depends_on_disk(): void
    {
        $service = $this->sourceOf(BotVisualIdentityService::class);
        $this->assertStringContainsString('App\\Modules\\Disk', $service, 'the visual identity service must reuse the Disk image machinery.');

        $job = $this->sourceOf(GenerateBotVisualJob::class);
        $this->assertStringContainsString('App\\Modules\\Disk', $job, 'the visual generation job must run the Disk worker path.');
    }

    /**
     * And the reverse stays FORBIDDEN across the WHOLE Disk module, not just one seam: Disk is the lower layer
     * every module writes files through, so a single reference back to Bot would turn a one-way dependency into
     * a cycle. Scanned by FILE (not by class) so a new Disk file cannot quietly open the edge.
     */
    public function test_no_disk_file_ever_names_bot(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path('modules/Disk')) as $file) {
            if (str_contains((string) file_get_contents($file), 'App\\Modules\\Bot')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'the Disk module must never name the Bot module (Bot → Disk is one-way).',
        );
    }

    /**
     * B6 adds the third deliberate downward edge: Bot → KNOWLEDGE. The bot reads a workspace knowledge
     * base instead of only its own JSON column, so this module names the Knowledge seams — and Knowledge
     * names nothing back (pinned module-wide, and literally, by KnowledgeModuleBoundaryTest, whose
     * forbidden list already includes this module).
     *
     * The half asserted HERE is the one that scan cannot see: the seam is INVERTED, so everything crossing
     * it is a PRIMITIVE. Knowledge is handed the morph alias `'bot'` and a uuid, never a Bot model — which
     * is what lets one base serve consumers that do not exist yet. A signature taking a Bot would compile
     * fine and pass every functional test in this suite; it would simply make the shared layer depend on
     * this one, one method at a time.
     */
    public function test_the_knowledge_seam_is_crossed_with_primitives_only(): void
    {
        $reader = $this->sourceOf(BotKnowledgeReader::class);
        $service = $this->sourceOf(BotKnowledgeService::class);

        $this->assertStringContainsString('App\\Modules\\Knowledge', $reader, 'the knowledge reader must call the Knowledge seams.');
        $this->assertStringContainsString('App\\Modules\\Knowledge', $service, 'the knowledge wiring must call the Knowledge seams.');

        // The alias the consumer identifies itself by — a registered morph alias, not an FQCN.
        $this->assertSame('bot', BotKnowledgeReader::BINDABLE_TYPE);
        $this->assertSame('bot', (new Bot)->getMorphClass());

        // And the seam on the OTHER side takes strings: no Knowledge signature may name a Bot type.
        foreach ([KnowledgeBindingService::class, KnowledgeRetrievalService::class, KnowledgeCompiler::class] as $fqcn) {
            $this->assertStringNotContainsString(
                'App\\Modules\\Bot',
                $this->sourceOf($fqcn),
                $fqcn . ' must stay Bot-agnostic (Bot → Knowledge is one-way).',
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function sourceOf(string $fqcn): string
    {
        $file = (new ReflectionClass($fqcn))->getFileName();
        $this->assertIsString($file);

        return (string) file_get_contents((string) $file);
    }
}
