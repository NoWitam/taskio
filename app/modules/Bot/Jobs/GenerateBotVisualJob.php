<?php

namespace App\Modules\Bot\Jobs;

use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotVisualIdentityService;
use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ONE async run of the bot visual-identity generator.
 *
 * It does NOT re-implement the image pipeline: it calls {@see ImageAiService::process()}, the exact
 * worker path the Disk preview editor uses (status transitions, meter + actor attribution, the
 * provider call, the result, the broadcast, input cleanup, the fail-fast paths for an over-budget
 * workspace and a safety rejection). The one thing it adds is the reason it exists at all — the
 * produced bytes have to become a FILE OWNED BY THE BOT, which the Disk job knows nothing about
 * (and must not: Disk never depends on Bot).
 *
 * That materialization is handed to `process()` as the result hook so it runs BEFORE the edit is
 * marked done: `done` is what wakes the client, so the candidate must already exist when it fires.
 *
 * Retry/timeout posture mirrors {@see \App\Modules\Disk\Jobs\EditDiskImageJob} — same provider, same
 * slow call, same at-least-once hazards.
 */
class GenerateBotVisualJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    // The provider HTTP call may run up to ai.image_timeout (120s), so the job's own alarm must
    // exceed it — mirrors EditDiskImageJob, including the retry_after > $timeout > ai.image_timeout
    // invariant that makes a slow-but-alive run safe.
    public int $timeout = 150;

    public function __construct(
        public string $botId,
        public string $editId,
        public string $workspaceId,
        public ?string $userId = null,
    ) {}

    /**
     * One run per edit at a time. A duplicate delivery must never re-call the BILLED provider or
     * file a second candidate, so it is released back rather than run.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->editId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(ImageAiService $images, BotVisualIdentityService $visual): void
    {
        $this->activateTenant();

        $bot = Bot::find($this->botId);

        if ($bot === null) {
            // The bot was deleted between dispatch and the worker: there is nowhere to file the
            // result, so do not pay for one. Close the status row instead of leaving it to the reaper.
            $images->fail($this->editId, __('disk.ai.failed'));

            return;
        }

        try {
            $images->process(
                $this->editId,
                $this->userId,
                fn (string $image) => $visual->attachCandidate($bot, $image, $this->userId),
            );
        } catch (Throwable $e) {
            // Log the real cause (kept OUT of the row, which only ever carries a localized message),
            // then rethrow so the queue retries and eventually routes to failed(). The prompt is user
            // content and is never part of this.
            Log::info("Bot visual generation failed for edit {$this->editId}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * All retries exhausted (or a non-retryable throw): close the status row with a localized,
     * non-secret message and drop its inputs. Restores tenancy first — failed() can run after the
     * queue listener has already popped this job's context.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        app(ImageAiService::class)->fail($this->editId, __('disk.ai.failed'));
    }

    /**
     * Re-apply the dispatching workspace so the tenant-scoped rows resolve (and, for an own-database
     * workspace, route to the right connection). Mirrors EditDiskImageJob: explicit rather than
     * relying on QueueTenancy, because both the status row and the bot's files are tenant-scoped and
     * failed() can run after the queue listener restored the previous context.
     */
    private function activateTenant(): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }
    }
}
