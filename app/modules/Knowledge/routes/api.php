<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Knowledge\Http\Controllers\KnowledgeBaseController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeDraftSessionController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeEntryController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeEntryRevisionController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeGraphController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeLinkController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeRelationController;
use App\Modules\Knowledge\Http\Controllers\KnowledgeSearchController;
use Illuminate\Support\Facades\Route;

/**
 * Knowledge module API routes.
 *
 * Every route is gated by RequireWorkspace, not merely by auth: a knowledge base is workspace-owned,
 * and ResolveWorkspace deliberately no-ops when the X-Workspace-Id header is absent — which would
 * leave WorkspaceScope inert and let an authenticated user reach another workspace's entry by id.
 * With the gate, a header-less request is refused before anything is bound. The middleware ORDER
 * (ResolveWorkspace → RequireWorkspace → SubstituteBindings) is what makes a FOREIGN id 404 at bind
 * rather than in a policy; it is pinned app-wide by ApiMiddlewarePriorityTest.
 *
 * Collections are nested under their base; single entries are flat. See KnowledgeEntryController for
 * why the item routes deliberately do not repeat the base segment.
 *
 * Literal segments are declared BEFORE wildcards throughout (`search`, `retry-index`, `restore`), and
 * every id parameter is whereUuid-constrained, so a literal can never be read as an id even if a
 * future edit reorders these.
 *
 * ------------------------------------------------------------------------------------------------
 * NOBODY WRITES KNOWLEDGE BY HAND — THE AI DOES
 *
 * The entries and relations in a base are AUTHORED BY THE COMPOSER, and a person's part is to approve,
 * refuse, or ask again in different words. So this file has no route that creates, edits or deletes an
 * entry or a relation, and it will not grow one back: the abilities behind them deny in the policies
 * too, so a request that skirted the route table would still be refused.
 *
 * What a person may still do is all here, and it is deliberately not small: read everything, run and
 * steer a drafting session (`refine`, `expand-context`), accept or reject each proposal, throw a whole
 * session away, dismiss a machine-suggested edge, configure the base itself — including its CHARTER,
 * which the erasure command requires an operator to be able to edit by hand — and retry a failed
 * index. Approving, refusing and directing: everything except writing.
 */
Route::middleware(['auth:sanctum', RequireWorkspace::class])
    ->prefix('knowledge')
    ->group(function () {
        // --- Search ----------------------------------------------------------------------
        // Declared FIRST so the literal `search` segment can never be read as a base id, whatever a
        // later edit does to the ordering below. Not paginated by design — see the controller.
        Route::get('search', [KnowledgeSearchController::class, 'workspace'])->name('knowledge.search');

        Route::get('bases/{base}/search', [KnowledgeSearchController::class, 'base'])
            ->name('knowledge.bases.search')
            ->whereUuid('base');

        // --- Graph -----------------------------------------------------------------------
        // `?entry=` turns the base overview into an ego graph; `depth` is capped at 2 and the node
        // count at `knowledge.graph.max_nodes`, with whatever the cap removed reported in the payload.
        Route::get('bases/{base}/graph', [KnowledgeGraphController::class, 'show'])
            ->name('knowledge.bases.graph')
            ->whereUuid('base');

        // --- Typed relations: READ ONLY ---------------------------------------------------
        //
        // THERE IS NO WAY TO WRITE A RELATION BY HAND, and that is the product decision rather than an
        // oversight: a relation is the AI's to propose and a person's to approve or refuse, through a
        // drafting session. `store`, `update`, `end` and `destroy` are gone, with their FormRequests,
        // and {@see \App\Modules\Knowledge\Policies\KnowledgeRelationPolicy} now denies all four so the
        // refusal does not depend on the route table.
        //
        // The SERVICE keeps every one of those operations — the composer and the applier call them the
        // moment a human accepts a proposal. What was removed is the surface that let a person call
        // them directly.
        Route::get('entries/{entry}/relations', [KnowledgeRelationController::class, 'index'])
            ->name('knowledge.relations.index')
            ->whereUuid('entry');

        // --- One edge --------------------------------------------------------------------
        // Dismissal is a STAMP on a machine-proposed edge, so its undo is the same path with DELETE
        // rather than a second verb: nothing is created and nothing is destroyed either way.
        Route::controller(KnowledgeLinkController::class)->prefix('links')->name('knowledge.links.')->group(function () {
            Route::post('/{link}/dismiss', 'dismiss')->name('dismiss')->whereUuid('link');
            Route::delete('/{link}/dismiss', 'restore')->name('undismiss')->whereUuid('link');
        });

        // --- The AI composer (B11a) -------------------------------------------------------
        // Drafting sessions produce INVISIBLE draft entries; these are the only routes in the module
        // that opt out of the draft-invisibility scope. Literal segments before wildcards throughout,
        // and every id whereUuid-constrained.
        Route::controller(KnowledgeDraftSessionController::class)->group(function () {
            // Asked BEFORE the composer form is rendered: budget, kill switch, and the input limits.
            Route::get('bases/{base}/compose-availability', 'availability')
                ->name('knowledge.compose.availability')
                ->whereUuid('base');

            Route::post('bases/{base}/draft-sessions', 'store')
                ->name('knowledge.draft-sessions.store')
                ->whereUuid('base');

            Route::get('draft-sessions/{session}', 'show')
                ->name('knowledge.draft-sessions.show')
                ->whereUuid('session');
            Route::post('draft-sessions/{session}/refine', 'refine')
                ->name('knowledge.draft-sessions.refine')
                ->whereUuid('session');
            Route::post('draft-sessions/{session}/accept', 'accept')
                ->name('knowledge.draft-sessions.accept')
                ->whereUuid('session');
            // Re-point a shadow at its target's current revision. No AI.
            Route::post('draft-sessions/{session}/rebase', 'rebase')
                ->name('knowledge.draft-sessions.rebase')
                ->whereUuid('session');
            // The proposal's relation preview, in the base graph's own shape. Cached on the session;
            // an unchanged re-open costs no AI.
            Route::get('draft-sessions/{session}/relations', 'relations')
                ->name('knowledge.draft-sessions.relations')
                ->whereUuid('session');
            // Retrieve context again — SPENDS one embedding, hence its own explicit action.
            Route::post('draft-sessions/{session}/expand-context', 'expandContext')
                ->name('knowledge.draft-sessions.expand-context')
                ->whereUuid('session');
            Route::delete('draft-sessions/{session}', 'destroy')
                ->name('knowledge.draft-sessions.destroy')
                ->whereUuid('session');

            // Rejecting ONE draft. Declared here (not under `entries/`) because it resolves the entry
            // with withDrafts() — the ordinary {entry} binding cannot see a draft at all.
            Route::delete('entries/{entry}/draft', 'rejectDraft')
                ->name('knowledge.entries.reject-draft')
                ->whereUuid('entry');
        });

        // The two TEXTS a draft diff compares (the client renders the diff). Same withDrafts() reason
        // as above, which is why it sits here rather than in the revisions group below.
        Route::get('entries/{entry}/draft-diff', [KnowledgeEntryRevisionController::class, 'draftDiff'])
            ->name('knowledge.entries.draft-diff')
            ->whereUuid('entry');

        // --- Bases -----------------------------------------------------------------------
        Route::controller(KnowledgeBaseController::class)->prefix('bases')->name('knowledge.bases.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');

            // Lifecycle endpoints resolve the row with withTrashed(), so they take a raw {id}.
            Route::post('/{id}/restore', 'restore')->name('restore')->whereUuid('id');
            Route::delete('/{id}/force', 'forceDestroy')->name('force-destroy')->whereUuid('id');

            Route::get('/{base}', 'show')->name('show')->whereUuid('base');
            Route::patch('/{base}', 'update')->name('update')->whereUuid('base');
            Route::delete('/{base}', 'destroy')->name('destroy')->whereUuid('base');
        });

        // --- Entries: READ ONLY -------------------------------------------------------------
        //
        // AN ENTRY IS WRITTEN BY THE COMPOSER AND BY NOTHING ELSE. `store`, `update`, `destroy`,
        // `restore`, `forceDestroy` and `reorder` are gone: a person approves or refuses a proposal,
        // and everything that reaches the base reaches it that way.
        //
        // `reorder` went with them although it changes no text. `position` is the base's canonical
        // order — it is what `index` sorts every reader's list by — so rearranging it is editorial
        // control over the base's structure, which is the thing being withdrawn. The composer still
        // sets it, at the end, through `nextPosition()`.
        //
        // `retry-index` STAYS. It re-queues an indexing run that failed; it authors nothing, changes no
        // text, and the alternative is an entry that is silently unsearchable with no way back.
        Route::get('bases/{base}/entries', [KnowledgeEntryController::class, 'index'])
            ->name('knowledge.entries.index')
            ->whereUuid('base');

        Route::controller(KnowledgeEntryController::class)->prefix('entries')->name('knowledge.entries.')->group(function () {
            // Literal before the wildcard, so `retry-index` can never be read as an id.
            Route::post('/{entry}/retry-index', 'retryIndex')->name('retry-index')->whereUuid('entry');

            Route::get('/{entry}', 'show')->name('show')->whereUuid('entry');
        });

        // --- An entry's version history: READ ONLY -------------------------------------------
        //
        // The history is still readable — it is the record of what the AI wrote and a human approved,
        // and reading it is how anybody audits that. RESTORING a revision is gone: it republishes an
        // old body under the current entry, which is authoring its text by another route.
        Route::get('entries/{entry}/revisions', [KnowledgeEntryRevisionController::class, 'index'])
            ->name('knowledge.revisions.index')
            ->whereUuid('entry');
    });
