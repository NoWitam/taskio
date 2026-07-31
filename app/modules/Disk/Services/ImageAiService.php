<?php

namespace App\Modules\Disk\Services;

use App\Modules\Disk\Enums\DiskAiEditStatus;
use App\Modules\Disk\Events\DiskAiEditUpdated;
use App\Modules\Disk\Exceptions\ImageSafetyRejectedException;
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
use Throwable;

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
 * A RETRY NEVER RE-BUYS A DELIVERED IMAGE. The worker path is split so the one PAID step
 * ({@see produce()}) records its result on the row the instant it returns, and a re-entry resumes
 * from there: everything after it — the caller's materialization hook, the terminal write, the
 * broadcast — is replayable for free. Without that split, any failure downstream of the provider
 * call sent the queue's retry back through it and the workspace paid again for the same edit.
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
    /** The metered channel of an image EDIT (an input image is present). */
    public const CHANNEL_EDIT = 'ai_image_edit';

    /** The metered channel of a text→image GENERATION (no input image). */
    public const CHANNEL_GENERATE = 'ai_image_generate';

    public function __construct(
        private OpenAiImageEditClient $client,
        private TenantContext $tenant,
        private MeteredAiCall $meter,
        private MeterContext $meterContext,
        private ImageGenerateService $generator,
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
        $edit = $this->prepare($image->get(), $prompt, $mask?->get());

        // Pass scalars, never the model: the worker reloads a FRESH row (avoids a stale snapshot)
        // and the workspace id lets it re-establish tenancy outside the request (see the job). The
        // acting user id rides along so the worker (which has NO auth() of its own) can attribute the
        // metered spend to the user — the meter's DEFAULT resolution would otherwise record it as
        // unattributed (R2 sub-stage 4 actor attribution; captured here where auth() is present).
        EditDiskImageJob::dispatch($edit->id, (string) $this->tenant->id(), auth()->id());

        return $edit;
    }

    /**
     * Everything {@see dispatch()} does EXCEPT queueing the job: enforce + count the budget, create
     * the status row, and persist the inputs. Split out so another module can ride this exact
     * machinery — same row, same caps, same meter gate, same poll/broadcast surface — while owning
     * the JOB that runs afterwards (the Bot module's visual identity generator does; it has to
     * materialize its own result before the edit is published as done). The alternative was a
     * second, near-identical async image pipeline.
     *
     * Inputs are raw BYTES, not uploads: a caller may be working from an image it already holds
     * (a file picked off the Disk, a produced blob) rather than a live multipart upload.
     *
     * A NULL image means text→image GENERATION rather than an edit; the mode is derived from the
     * persisted input path in {@see process()}, so it needs no column of its own. Callers must pass
     * the matching $channel so the pre-flight gate prices the right thing.
     *
     * @param  string  $channel  the metered channel: self::CHANNEL_EDIT or self::CHANNEL_GENERATE
     */
    public function prepare(?string $image, string $prompt, ?string $mask = null, string $channel = self::CHANNEL_EDIT): DiskAiEdit
    {
        $this->enforceDailyBudget();

        // Pre-flight the shared cost meter's gate BEFORE queuing so an over-cap workspace is refused
        // up front (a 429), not left to fail a worker later. The day-count cap above is the secondary
        // backstop; a cap of 0 (the default) makes this a no-op.
        try {
            $this->meter->assertWithinBudget($channel);
        } catch (AiBudgetExceededException) {
            abort(429, __('disk.ai.budget'));
        }

        $edit = DiskAiEdit::create([
            'status' => DiskAiEditStatus::Queued,
            'prompt' => $prompt,
            'has_mask' => $mask !== null,
        ]);

        $directory = $this->inputDirectory($edit);

        $imagePath = null;
        if ($image !== null) {
            $imagePath = $directory . '/image.png';
            Storage::put($imagePath, $image);
        }

        $maskPath = null;
        if ($mask !== null) {
            $maskPath = $directory . '/mask.png';
            Storage::put($maskPath, $mask);
        }

        $edit->update([
            'input_image_path' => $imagePath,
            'input_mask_path' => $maskPath,
        ]);

        // Count at dispatch (not on success): a queued edit already reserved provider capacity.
        $this->recordUsage();

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
     *
     * MODE is derived, not stored: a row with a persisted input image is an EDIT, one without is a
     * text→image GENERATION (see {@see prepare()}). Each meters its own channel.
     *
     * $onResult runs with the finished base64 image BEFORE the row is marked done — the seam a
     * caller uses to MATERIALIZE the result (the Bot module files it as the bot's image). Ordering
     * is the point: `done` is what wakes the client up (poll + broadcast), so anything the client
     * expects to find must already exist when it fires. A throwing hook fails the whole job, leaving
     * the edit non-terminal for the queue to retry.
     *
     * WHICH IS WHY THE RESULT IS PERSISTED BEFORE THE HOOK RUNS. The provider call is the only PAID
     * step here; the hook is not. Storing the image first makes the retry RESUMABLE: a re-entry finds
     * a row that already holds its result and replays only the unpaid half, so a broken hook costs
     * exactly one provider call instead of one per try (three, at this job's retry count — two of
     * them billed for images nobody ever sees). The row stays `processing` while it holds that result,
     * which is deliberate: `done` is the client's signal that everything downstream exists, and the
     * result column is never exposed for a non-done row ({@see DiskAiEditResource}). If the hook never
     * succeeds the job's failed() hook closes the row as failed WITH the paid result still on it — an
     * honest terminal state, and the retention prune reclaims the bytes.
     *
     * @param  (callable(string): void)|null  $onResult
     */
    public function process(string $editId, ?string $userId = null, ?callable $onResult = null): void
    {
        $edit = DiskAiEdit::find($editId);

        if ($edit === null || $edit->status->isTerminal()) {
            return;
        }

        $edit->update(['status' => DiskAiEditStatus::Processing]);

        $image = $this->produce($edit, $userId);

        // A fail-FAST path (over-budget / safety refusal) already closed the row terminally.
        if ($image === null) {
            return;
        }

        // Let the caller materialize the result BEFORE the edit goes terminal (see the docblock).
        if ($onResult !== null) {
            $onResult($image);
        }

        $edit->update(['status' => DiskAiEditStatus::Done]);

        $this->broadcastStatus($edit->id, DiskAiEditStatus::Done);

        $this->deleteInputs($edit);
    }

    /**
     * The PAID half of {@see process()}: the provider call, persisted onto the row the moment it returns.
     * Returns the base64 image, or NULL when a deterministic failure already closed the edit terminally
     * (over-cap budget / safety refusal — neither is worth a retry, so both fail fast rather than throw).
     *
     * RESUMES rather than re-spends: a row that already carries `result_image` is a retry of a delivery
     * whose provider call succeeded, so the stored bytes are returned and nothing is billed or metered
     * again. This is also why the persisted INPUTS are only read on the paying path — a resumed retry must
     * not fail merely because a previous pass (or the reaper) cleaned them up.
     */
    private function produce(DiskAiEdit $edit, ?string $userId): ?string
    {
        if (is_string($edit->result_image) && $edit->result_image !== '') {
            return $edit->result_image;
        }

        $image = $edit->input_image_path !== null ? Storage::get($edit->input_image_path) : null;
        if ($edit->input_image_path !== null && $image === null) {
            throw new RuntimeException("Disk AI edit [{$edit->id}] is missing its input image.");
        }

        $mask = $edit->input_mask_path !== null ? Storage::get($edit->input_mask_path) : null;

        // Tag the spend with the dispatching user (null type → the resolver defaults to the user alias);
        // cleared in the finally so a shared worker never leaks the actor into the next job.
        $this->meterContext->setActor(null, $userId);

        try {
            $result = $image !== null
                ? $this->edit($image, $edit->prompt, $mask)
                : $this->generate($edit->prompt);
        } catch (AiBudgetExceededException) {
            // Over-cap: fail FAST rather than burn the job's retries — the calendar-month budget
            // cannot clear before they run out. Reuse the terminal fail() path (localized message +
            // input cleanup + poll/broadcast) and do NOT rethrow, so the queue does not retry.
            $this->fail($edit->id, __('disk.ai.budget'));

            return null;
        } catch (ImageSafetyRejectedException) {
            // The provider rendered the image and then refused to hand it over. Deterministic, so —
            // like the over-cap case — fail FAST without rethrowing: retrying pays for the same
            // refusal. Terminal state of its own so the client can say what actually happened
            // instead of a generic failure; the exception carries no prompt and is not logged here.
            $this->fail($edit->id, __('disk.ai.safety'), DiskAiEditStatus::SafetyRejected);

            return null;
        } finally {
            $this->meterContext->clearActor();
        }

        // The spend has happened — record it NOW, before anything that can throw, so no later failure
        // can send the retry back through a billed call (see process()'s note).
        $edit->update(['result_image' => $result['image']]);

        return $result['image'];
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
        return $this->meter->meter(self::CHANNEL_EDIT, fn () => $this->client->edit($image, $prompt, $mask));
    }

    /**
     * Perform a text→image GENERATION for a row that carries no input image. Delegates to
     * {@see ImageGenerateService}, which owns the laravel/ai call AND its own metering on the
     * `ai_image_generate` channel — so this method deliberately does NOT wrap it in the meter a
     * second time. Returns the same {image (base64), mime} shape as {@see edit()} so the worker
     * path is identical for both modes.
     *
     * laravel/ai surfaces a provider refusal as its own exception type rather than a response, so a
     * safety rejection is recognised from the message and re-thrown as the typed one the worker
     * knows how to fail fast on; anything else propagates untouched (and stays retryable).
     *
     * @return array{image: string, mime: string}
     */
    public function generate(string $prompt): array
    {
        try {
            $result = $this->generator->generate($prompt);
        } catch (ImageSafetyRejectedException|AiBudgetExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (ImageSafetyRejectedException::matches($e)) {
                throw new ImageSafetyRejectedException('The image provider refused the content (moderation_blocked).');
            }

            throw $e;
        }

        return ['image' => base64_encode($result['bytes']), 'mime' => $result['mime']];
    }

    /**
     * Mark an edit failed with a NON-SECRET message and drop its inputs. Called from the job's
     * failed() hook (provider gave up after retries) and from the reaper (stuck past the timeout).
     * Never overwrites an already-terminal row, but always cleans up its inputs.
     *
     * $status picks WHICH terminal failure is recorded; it only ever widens the stored reason, since
     * the broadcast (like the API) reports every failure as `failed`.
     */
    public function fail(string $editId, string $error, DiskAiEditStatus $status = DiskAiEditStatus::Failed): void
    {
        $edit = DiskAiEdit::find($editId);

        if ($edit === null) {
            return;
        }

        if (!$edit->status->isTerminal()) {
            $edit->update([
                'status' => $status,
                'error' => $error,
            ]);

            $this->broadcastStatus($edit->id, $status, $error);
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
            ->whereIn('status', array_map(
                fn (DiskAiEditStatus $status) => $status->value,
                array_filter(DiskAiEditStatus::cases(), fn (DiskAiEditStatus $status) => $status->isTerminal()),
            ))
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

        // The WIRE status, not the stored one: consumers branch on `done`/`failed`, so a stored-only
        // state (safety_rejected) must never reach the socket and strand a listener that is waiting
        // for a terminal value it recognises. The reason travels on the poll as `error_code`.
        broadcast(new DiskAiEditUpdated($workspaceId, $editId, $status->wireStatus(), $error));
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
