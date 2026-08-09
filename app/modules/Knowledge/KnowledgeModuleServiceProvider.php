<?php

namespace App\Modules\Knowledge;

use App\Modules\Knowledge\Console\PurgeKnowledgeSubjectCommand;
use App\Modules\Knowledge\Console\ReapAbandonedDraftSessionsCommand;
use App\Modules\Knowledge\Console\ReapStaleKnowledgeIndexCommand;
use App\Modules\Knowledge\Console\SweepKnowledgeIndexCommand;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Policies\KnowledgeBasePolicy;
use App\Modules\Knowledge\Policies\KnowledgeEntryPolicy;
use App\Modules\Knowledge\Policies\KnowledgeLinkPolicy;
use App\Modules\Knowledge\Policies\KnowledgeRelationPolicy;
use App\Modules\Knowledge\Support\LaravelAiEmbedder;
use App\Modules\Knowledge\Support\PgVectorSimilaritySearch;
use App\Modules\Knowledge\Support\PhpCosineSimilaritySearch;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Knowledge module owns the workspace KNOWLEDGE BASE — the durable, retrievable facts a
 * workspace teaches the product once and every AI consumer reads back. It is a LOW layer,
 * deliberately placed on the same tier as Variables: it may depend on the Variables module and on
 * App\Support, and it must import NOTHING from the modules that will CONSUME it. That one-way
 * direction is what lets several unrelated consumers share one knowledge base without any of them
 * becoming a dependency of it — and it is pinned literally by KnowledgeModuleBoundaryTest, which
 * scans every PHP file under this root (comments and docblocks included).
 *
 * Registered directly AFTER VariablesModuleServiceProvider in bootstrap/providers.php so the load
 * order mirrors that dependency direction.
 *
 * B1 is the persistence + CRUD layer: bases, entries, append-only revisions, the wikilink graph and
 * the chunk table. B2a adds the module's FIRST AI spend — chunking, embedding and differential
 * indexing — entirely behind `knowledge.index.enabled` and metered on the shared `ai_embedding`
 * channel. B2b is RETRIEVAL: hybrid search (keyword + vector, fused by reciprocal rank), materialised
 * similarity edges, the link graph, and the base aggregates a card is rendered from. Every read here
 * is answerable WITHOUT an AI call except the search's query embedding, which is metered on the same
 * channel and degrades to the keyword leg when it cannot be made.
 */
class KnowledgeModuleServiceProvider extends ServiceProvider
{
    /**
     * The two seams this module reads and writes vectors through.
     *
     * The EMBEDDER is bound as a SINGLETON so a test can swap in
     * {@see \App\Modules\Knowledge\Support\FakeKnowledgeEmbedder} and then assert against the very
     * instance the indexer used — its call counter is the only way to observe spend from outside a
     * fire-and-forget job. A per-resolution binding would hand the test a different object than the
     * one that did the work.
     *
     * The SIMILARITY SEARCH is deliberately NOT a singleton: it is stateless, and a per-resolution
     * binding is what lets `knowledge.vector_store` be switched at runtime — which is exactly how the
     * PHP fallback is exercised at all. A singleton would freeze whichever backend happened to be
     * configured when the container first resolved it, and the escape hatch would be untestable.
     */
    public function register(): void
    {
        $this->app->singleton(KnowledgeEmbedder::class, LaravelAiEmbedder::class);

        $this->app->bind(KnowledgeSimilaritySearch::class, fn ($app) => config('knowledge.vector_store') === 'php'
            ? $app->make(PhpCosineSimilaritySearch::class)
            : $app->make(PgVectorSimilaritySearch::class));
    }

    public function boot(): void
    {
        $this->commands([
            SweepKnowledgeIndexCommand::class,
            ReapStaleKnowledgeIndexCommand::class,
            // Deletes user data (abandoned drafting sessions + their drafts), which is why it is a
            // sibling of the stale-index reaper rather than a branch inside it.
            ReapAbandonedDraftSessionsCommand::class,
            // Operator-invoked and deliberately NOT scheduled: erasure is irreversible and always
            // begins with a human reading a dry run.
            PurgeKnowledgeSubjectCommand::class,
        ]);

        // The morph map is ENFORCED app-wide (every module registers its own aliases), so an unmapped
        // model throws on getMorphClass(). Registering these is what lets a knowledge base or entry be
        // the SUBJECT of a morph — a creator attribution, a comment, a changelog — and it must be in
        // place before any such row can exist. Only the two USER-FACING roots are mapped: revisions,
        // links and chunks are derived rows nothing points at polymorphically, and an alias nobody
        // uses is an alias nobody can safely rename later.
        Relation::enforceMorphMap([
            'knowledge_base' => KnowledgeBase::class,
            'knowledge_entry' => KnowledgeEntry::class,
        ]);

        Gate::policy(KnowledgeBase::class, KnowledgeBasePolicy::class);
        Gate::policy(KnowledgeEntry::class, KnowledgeEntryPolicy::class);
        // Only one ability (dismissing a machine-proposed edge); edges are otherwise derived rows
        // nobody acts on directly.
        Gate::policy(KnowledgeLink::class, KnowledgeLinkPolicy::class);
        // A RELATION, unlike a link, is something somebody ASSERTED — so it gets a full set of
        // abilities, with deletion restricted where the reversible ones are not.
        Gate::policy(KnowledgeRelation::class, KnowledgeRelationPolicy::class);

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/routes/api.php');
    }
}
