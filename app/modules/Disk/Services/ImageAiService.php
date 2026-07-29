<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Events\DiskAiEditUpdated;
use App\Modules\Disk\Jobs\EditDiskImageJob;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Support\MeterContext;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Async AI image edits for the Disk preview editor: the CURRENT canvas (+ an optional brushed MASK
 * for inpainting / object removal / background replace) is posted with a prompt, and the provider
 * returns the edited image.
 *
 * The provider call is slow (tens of seconds), so an edit is QUEUED rather than run inline:
 * {@see dispatch()} persists the inputs, records a status row and queues {@see EditDiskImageJob};
 * the worker calls {@see process()} (which runs {@see edit()} — the actual client call) and the
 * browser polls the row until done/failed. A soft per-workspace DAILY cap bounds spend and is
 * enforced+counted at DISPATCH so a pending edit already consumes it.
 *
 * On top of that day-count cap, every provider spend routes through the shared Variables cost METER
 * ({@see MeteredAiCall}) — the correct one-way edge (Disk depends on the lower Variables layer, never
 * the reverse). The meter GATES BEFORE spend on the workspace's calendar-month $ (cost) budget: dispatch
 * pre-flights it so an over-cap workspace gets a clean 429 before a job is queued, and the worker's
 * client call is wrapped so the spend is recorded. An over-cap throw in the worker is caught and fails
 * the edit FAST — no burned retries, since the budget cannot clear within the job's retry window — while
 * a real provider/transport error STILL retries as before.
 *
 * Rides the custom {@see OpenAiImageEditClient} (OpenAI images/edits with a mask — laravel/ai
 * cannot send one).
 */
class ImageAiService
{
    public function __construct(
        private OpenAiImageEditClient $client,
        private TenantContext $tenant,
        private MeteredAiCall $meter,
        private MeterContext $meterContext,
    ) {}

    // ---- Dispatch (request path) -----------------------------------------------------

    /**
     * Queue an AI image edit and return its status row. Budget is enforced AND counted HERE, at
     * dispatch — a queued edit consumes the workspace's daily cap immediately, so the queue cannot
     * be flooded past the cap while jobs are still pending. The uploaded image (+ optional mask) is
     * persisted under the edit's own prefix because the worker runs after the request and can no
     * longer read its temp files.
     */
    public function dispatch(UploadedFile $image, string $prompt, ?UploadedFile $mask = null): DiskAiEdit
    {
        $this->enforceDailyBudget();

        // Pre-flight the shared token meter's gate BEFORE queuing so an over-cap workspace is refused
        // up front (a 429), not left to fail a worker later. The day-count cap above is the secondary
        // backstop; a cap of 0 (the default) makes this a no-op.
        try {
            $this->meter->assertWithinBudget('ai_image_edit');
        } catch (AiBudgetExceededException) {
            abort(429, __('disk.ai.budget'));
        }

        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Queued,
            'prompt' => $prompt,
            'has_mask' => $mask !== null,
        ]);

        $directory = $this->inputDirectory($edit);

        $imagePath = $directory . '/image.png';
        Storage::put($imagePath, $image->get());

        $maskPath = null;
        if ($mask !== null) {
            $maskPath = $directory . '/mask.png';
            Storage::put($maskPath, $mask->get());
        }

        $edit->update([
            'input_image_path' => $imagePath,
            'input_mask_path' => $maskPath,
        ]);

        // Count at dispatch (not on success): a queued edit already reserved provider capacity.
        $this->recordUsage();

        // Pass scalars, never the model: the worker reloads a FRESH row (avoids a stale snapshot)
        // and the workspace id lets it re-establish tenancy outside the request (see the job). The
        // acting user id rides along so the worker (which has NO auth() of its own) can attribute the
        // metered spend to the user — the meter's DEFAULT resolution would otherwise record it as
        // unattributed (R2 sub-stage 4 actor attribution; captured here where auth() is present).
        EditDiskImageJob::dispatch($edit->id, (string) $this->tenant->id(), auth()->id());

        return $edit;
    }

    // ---- Worker path -----------------------------------------------------------------

    /**
     * Run a queued edit through the provider: mark it processing, read the persisted input(s), call
     * the client, store the result, mark it done, and delete the inputs. Idempotent — a missing or
     * already-terminal row is a no-op (a retry that lands after the reaper gave up, or a duplicate
     * delivery, must never resurrect or double-run an edit). A provider/transport failure
     * propagates: the job's failed() hook records it (see {@see EditDiskImageJob}). An over-cap
     * {@see AiBudgetExceededException} is the ONE exception caught here — the budget can never clear
     * within the job's retry window, so the edit is failed FAST (via {@see fail()}) and NOT rethrown,
     * sparing the retries. fail() is terminal-safe, so idempotency/overlap semantics are unaffected.
     *
     * $userId is the acting user captured at dispatch (the worker has no auth()): it is tagged on the
     * ambient MeterContext so the metered spend attributes to that user (R2 sub-stage 4). null → the
     * meter's default resolution (unattributed here, since a worker has no auth/run).
     */
    public function process(string $editId, ?string $userId = null): void
    {
        $edit = DiskAiEdit::find($editId);

        if ($edit === null || $edit->status->isTerminal()) {
            return;
        }

        $edit->update(['status' => DiskAiEditStatus::Processing]);

        $image = $edit->input_image_path !== null ? Storage::get($edit->input_image_path) : null;
        if ($image === null) {
            throw new RuntimeException("Disk AI edit [{$edit->id}] is missing its input image.");
        }

        $mask = $edit->input_mask_path !== null ? Storage::get($edit->input_mask_path) : null;

        // Tag the spend with the dispatching user (null type → the resolver defaults to the user alias);
        // cleared in the finally so a shared worker never leaks the actor into the next job.
        $this->meterContext->setActor(null, $userId);

        try {
            $result = $this->edit($image, $edit->prompt, $mask);
        } catch (AiBudgetExceededException) {
            // Over-cap: fail FAST rather than burn the job's retries — the calendar-month budget
            // cannot clear before they run out. Reuse the terminal fail() path (localized message +
            // input cleanup + poll/broadcast) and do NOT rethrow, so the queue does not retry.
            $this->fail($edit->id, __('disk.ai.budget'));

            return;
        } finally {
            $this->meterContext->clearActor();
        }

        $edit->update([
            'status' => DiskAiEditStatus::Done,
            'result_image' => $result['image'],
        ]);

        $this->broadcastStatus($edit->id, DiskAiEditStatus::Done);

        $this->deleteInputs($edit);
    }

    /**
     * Perform the actual provider call. Kept as its own unit (a provider/transport failure surfaces
     * as the client's RuntimeException) so the queued worker owns one clear edit path. Inputs are
     * raw PNG bytes — read from storage by the worker, never a live upload.
     *
     * @return array{image: string, mime: string}
     */
    public function edit(string $image, string $prompt, ?string $mask = null): array
    {
        // Route the spend through the shared meter: it re-checks the gate (an over-cap throw here is
        // caught by process(), which fails the edit FAST without retrying) and RECORDS the spend as the
        // configured ai_image_edit unit (the client result carries no provider token count).
        return $this->meter->meter('ai_image_edit', fn () => $this->client->edit($image, $prompt, $mask));
    }

    /**
     * Mark an edit failed with a NON-SECRET message and drop its inputs. Called from the job's
     * failed() hook (provider gave up after retries) and from the reaper (stuck past the timeout).
     * Never overwrites an already-terminal row, but always cleans up its inputs.
     */
    public function fail(string $editId, string $error): void
    {
        $edit = DiskAiEdit::find($editId);

        if ($edit === null) {
            return;
        }

        if (!$edit->status->isTerminal()) {
            $edit->update([
                'status' => DiskAiEditStatus::Failed,
                'error' => $error,
            ]);

            $this->broadcastStatus($edit->id, DiskAiEditStatus::Failed, $error);
        }

        $this->deleteInputs($edit);
    }

    // ---- Reaper / housekeeping -------------------------------------------------------

    /**
     * Mark edits stranded in queued/processing past the timeout as failed — a worker killed mid-run
     * (SIGKILL/OOM) never fires failed(), and nothing else would recover them. The timeout exceeds
     * the job's whole retry budget so a slow-but-alive retrying edit is never reaped. Returns the
     * number reaped.
     */
    public function reapStale(): int
    {
        $timeout = max(60, (int) config('ai.disk_image_edit_timeout', 900));
        $cutoff = now()->subSeconds($timeout);

        $stale = DiskAiEdit::query()
            ->whereIn('status', [DiskAiEditStatus::Queued->value, DiskAiEditStatus::Processing->value])
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($stale as $edit) {
            $this->fail($edit->id, __('disk.ai.failed'));
        }

        return $stale->count();
    }

    /**
     * Delete terminal (done/failed) edits older than the retention window, plus any input bytes
     * that somehow lingered. Keeps the table — which stores multi-MB base64 results — from growing
     * without bound. Returns the number pruned.
     */
    public function pruneTerminal(): int
    {
        $retention = max(60, (int) config('ai.disk_image_edit_retention', 3600));
        $cutoff = now()->subSeconds($retention);

        $old = DiskAiEdit::query()
            ->whereIn('status', [DiskAiEditStatus::Done->value, DiskAiEditStatus::Failed->value])
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($old as $edit) {
            $this->deleteInputs($edit);
            $edit->delete();
        }

        return $old->count();
    }

    // ---- Internals -------------------------------------------------------------------

    /** Storage prefix for one edit's persisted inputs, namespaced per workspace for tenant GC. */
    private function inputDirectory(DiskAiEdit $edit): string
    {
        return 'disk-ai/' . ($this->tenant->id() ?? 'none') . '/' . $edit->id;
    }

    /** Drop an edit's persisted inputs (once processed) and forget their paths. */
    private function deleteInputs(DiskAiEdit $edit): void
    {
        $paths = array_values(array_filter([$edit->input_image_path, $edit->input_mask_path]));

        if ($paths === []) {
            return;
        }

        Storage::delete($paths);
        $edit->update(['input_image_path' => null, 'input_mask_path' => null]);
    }

    /**
     * PUSH a lightweight terminal-status notification to the workspace's private channel so the
     * browser can stop polling (and, on done, fetch the image via GET /disk/ai/image/{id}). Carries
     * NO image — the multi-MB base64 result exceeds the websocket per-message limit; status + an
     * optional error only. Skipped when no workspace is active (the worker/job's failed() hook run
     * inside the dispatching tenant, but the reaper fails SHARED-DB edits with the context cleared —
     * those rely on the poll fallback, so guard rather than broadcast to the wrong/absent workspace).
     */
    private function broadcastStatus(string $editId, DiskAiEditStatus $status, ?string $error = null): void
    {
        $workspaceId = $this->tenant->id();

        if ($workspaceId === null) {
            return;
        }

        broadcast(new DiskAiEditUpdated($workspaceId, $editId, $status->value, $error));
    }

    /** The per-workspace, per-day usage counter key. */
    private function budgetKey(): string
    {
        return 'disk-ai:' . ($this->tenant->id() ?? 'none') . ':' . now()->format('Y-m-d');
    }

    /** Refuse (429) once the workspace has hit its daily cap. A cap of 0 disables the limit. */
    private function enforceDailyBudget(): void
    {
        $max = (int) config('ai.disk_image_max_per_day');
        if ($max <= 0) {
            return;
        }
        if ((int) Cache::get($this->budgetKey(), 0) >= $max) {
            abort(429, __('disk.ai.budget'));
        }
    }

    /** Count a dispatched edit against the daily budget (a 1-day TTL bucket per workspace). */
    private function recordUsage(): void
    {
        if ((int) config('ai.disk_image_max_per_day') <= 0) {
            return;
        }
        $key = $this->budgetKey();
        Cache::add($key, 0, now()->addDay()); // seed the bucket with its TTL only if absent
        Cache::increment($key);
    }
}
