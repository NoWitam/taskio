<?php

use App\Modules\Generator\Http\Controllers\ContentTypeController;
use App\Modules\Generator\Http\Controllers\GenerationSessionController;
use App\Modules\Generator\Http\Controllers\TemplateCatalogController;
use App\Modules\Generator\Http\Controllers\TemplateController;
use App\Modules\Generator\Http\Controllers\TemplatePreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // The code-defined CONTENT TYPE catalog the editor builds its data-driven sections from.
    Route::get('generator/content-types', ContentTypeController::class)->name('generator.content-types');

    // The DRAFT-FRIENDLY, server-authoritative catalog + FAITHFUL preview for the template editor.
    // POST (the in-progress slots / content ride the body). Declared BEFORE the templates resource so the
    // static `content-types` / `catalog` / `preview` segments never bind as a {template} id.
    Route::post('generator/catalog', TemplateCatalogController::class)->name('generator.catalog');
    Route::post('generator/preview', TemplatePreviewController::class)->name('generator.preview');

    // CRUD for TEMPLATES — user-created, workspace-scoped reusable prompts with declared typed slots.
    // Workspace-member read + creator-only mutation (TemplatePolicy). apiResource binds Template as
    // {template} (the singular of the last URI segment).
    Route::apiResource('generator/templates', TemplateController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);

    // Generation SESSIONS — one execution of a template recipe into content (R2 sub-stage 2b). CRUD +
    // the async generate action (claim + queue). Declared BEFORE the {session} apiResource so the static
    // `generate` segment never binds as an id. Workspace-member read + creator-only mutation
    // (GenerationSessionPolicy); {session} binds GenerationSession (soft-deleted rows 404 at bind).
    Route::post('generator/sessions/{session}/generate', [GenerationSessionController::class, 'generate'])
        ->name('generator.sessions.generate');

    // Produced-image serve + Save-to-Disk (R2 sub-stage 2c). Declared BEFORE the {session} apiResource so the
    // static `parts` segment never binds as an id. {session} is tenant-scoped (foreign/soft-deleted → 404 at
    // bind); {partKey} is a per-part storage key (e.g. `image`, `scene_plan.0`), NOT a security boundary.
    Route::get('generator/sessions/{session}/parts/{partKey}/image', [GenerationSessionController::class, 'partImage'])
        ->whereUuid('session')
        ->name('generator.sessions.part-image');
    Route::post('generator/sessions/{session}/parts/{partKey}/save-to-disk', [GenerationSessionController::class, 'saveToDisk'])
        ->whereUuid('session')
        ->name('generator.sessions.save-to-disk');

    // Per-part REFINE loop (R2 sub-stage 2d): regenerate (fresh snapshot variation) / refine (instructed
    // revision of the current output) are async claim+dispatch part ops; undo is synchronous. All authorize
    // session `update` (creator); {session} is tenant-scoped (foreign → 404 at bind); {partKey} is validated
    // against the snapshot's content-type parts (unknown → 404) in the refiner. Declared BEFORE the {session}
    // apiResource so the static `parts` segment never binds as an id.
    Route::post('generator/sessions/{session}/parts/{partKey}/regenerate', [GenerationSessionController::class, 'regenerate'])
        ->whereUuid('session')
        ->name('generator.sessions.part-regenerate');
    Route::post('generator/sessions/{session}/parts/{partKey}/refine', [GenerationSessionController::class, 'refine'])
        ->whereUuid('session')
        ->name('generator.sessions.part-refine');
    Route::post('generator/sessions/{session}/parts/{partKey}/undo', [GenerationSessionController::class, 'undo'])
        ->whereUuid('session')
        ->name('generator.sessions.part-undo');

    // Archive / un-archive (R2 sub-stage 2d): set/clear `archived_at`, which EXEMPTS a session from the
    // lifecycle reaper's trash + purge windows. Creator-only (session `update`); {session} is tenant-scoped
    // (foreign/soft-deleted → 404 at bind). Declared BEFORE the {session} apiResource so the static
    // `archive` / `unarchive` segments never bind as an id.
    Route::post('generator/sessions/{session}/archive', [GenerationSessionController::class, 'archive'])
        ->name('generator.sessions.archive');
    Route::post('generator/sessions/{session}/unarchive', [GenerationSessionController::class, 'unarchive'])
        ->name('generator.sessions.unarchive');

    Route::apiResource('generator/sessions', GenerationSessionController::class)
        ->parameter('sessions', 'session')
        ->only(['index', 'show', 'store', 'update', 'destroy']);
});
