<?php

namespace App\Modules\Bot\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Bot\DTOs\BotVisualGenerationDTO;
use App\Modules\Bot\Jobs\GenerateBotVisualJob;
use App\Modules\Bot\Models\Bot;
use App\Modules\Disk\Models\DiskAiEdit;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Services\FileService;
use App\Modules\Disk\Services\ImageAiService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating and curating a bot's LIKENESS — the visual module's behaviour, and the Bot module's
 * deliberate one-way edge to Disk (Disk never names Bot; pinned by BotModuleBoundaryTest).
 *
 * Two ways in, one pipeline out:
 *   - REFERENCE   an image the user supplies (a fresh upload, or a pick from their Disk) is edited
 *                 towards the written identity — the way to keep a face they already have;
 *   - DESCRIPTION the written identity alone is turned into an image — the way to invent one.
 *
 * Both ride the DISK's async image machinery rather than a second copy of it: the same
 * {@see DiskAiEdit} status row, the same daily cap, the same cost-meter gate BEFORE spend, the same
 * poll (`GET /api/disk/ai/image/{id}`) and broadcast the Disk preview editor already uses. What is
 * new here is only what happens AFTERWARDS: the produced bytes become a FILE OWNED BY THE BOT
 * (`fileable_type = 'bot'`) and land in the module's candidate strip — which is why the run needs a
 * job of its own ({@see GenerateBotVisualJob}) instead of the Disk one.
 *
 * The bot's files are resource files, so they are invisible to the Disk browser (which lists only
 * disk-native files) and to the "Zasoby" tree (which walks only registered resource types).
 *
 * The identity itself is read from the PERSISTED module, never from the request: what gets drawn is
 * always what the user saved.
 */
class BotVisualIdentityService
{
    /**
     * How many iterations the module keeps. A strip the user chooses from, not an archive: when a
     * seventh arrives the oldest UNAPPROVED one is dropped and its bytes deleted, so a bot cannot
     * accumulate unbounded provider output. The approved likeness is never evicted.
     */
    public const MAX_CANDIDATES = 6;

    public function __construct(
        private ImageAiService $images,
        private FileService $files,
        private TenantContext $tenant,
    ) {}

    // ---- Generation ------------------------------------------------------------------

    /**
     * Start ONE identity generation and return its status row (the client polls it on the Disk's
     * existing endpoint). Ordering is deliberate:
     *
     *   1. read the source bytes (a 422 here costs nothing),
     *   2. compose the prompt from the PERSISTED identity + this run's instruction,
     *   3. {@see ImageAiService::prepare()} — the daily cap + the cost-meter gate BEFORE spend, so an
     *      over-budget workspace is refused (429) before anything is written,
     *   4. only then persist the source + prompt on the bot and queue the run.
     *
     * A fresh UPLOAD becomes a bot-owned file so the module can re-generate from it later; a Disk
     * pick is referenced in place (it belongs to the disk, and copying it would orphan a duplicate).
     */
    public function generate(Bot $bot, BotVisualGenerationDTO $dto): DiskAiEdit
    {
        $source = $this->sourceBytes($bot, $dto);
        $prompt = $this->composePrompt($bot, $dto);

        $edit = $this->images->prepare($source, $prompt, null, $dto->mode->channel());

        DB::transaction(function () use ($bot, $dto, $source, $prompt) {
            // An upload has to be persisted to be referenceable; a picked file already is.
            $reference = $dto->reference !== null
                ? $this->files->storeContent(
                    (string) $source,
                    $this->fileName($bot, 'reference', $dto->reference->getClientOriginalExtension() ?: 'png'),
                    (string) ($dto->reference->getMimeType() ?: 'image/png'),
                    $bot,
                )
                : ($dto->referenceFileId !== null ? $this->resolveFile($bot, $dto->referenceFileId) : null);

            $identity = $this->identityOf($bot);
            $identity['prompt'] = $prompt;

            if ($reference !== null) {
                $previous = $identity['reference_file_id'];
                $identity['reference_file_id'] = $reference->getKey();

                // Housekeeping: a replaced reference the BOT owns and nothing else points at is dead
                // weight — drop it. A disk-native reference is someone else's file: never touched.
                $this->forgetOwnedFile($bot, $previous, $identity);
            }

            $this->writeIdentity($bot, $identity);
        });

        // Scalars only (the worker reloads fresh rows), plus the workspace id so it can re-establish
        // tenancy off the request and the acting user so the metered spend stays attributed.
        GenerateBotVisualJob::dispatch(
            $bot->getKey(),
            $edit->id,
            (string) $this->tenant->id(),
            auth()->id(),
        );

        return $edit;
    }

    /**
     * File the finished image as one of the bot's candidates. Called from the worker BEFORE the edit
     * is published as done, so a client woken by the poll/broadcast always finds the candidate
     * already there.
     *
     * The whole read-modify-write runs under a row lock: the visual module is ONE json column, and a
     * user saving the bot while a generation lands would otherwise clobber whichever write lost.
     * Evicted bytes are deleted only AFTER the commit — storage is not transactional, so deleting
     * inside would destroy the blob of a row that then rolls back.
     */
    public function attachCandidate(Bot $bot, string $base64Image, ?string $userId = null): ?File
    {
        $bytes = base64_decode($base64Image, true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        [$file, $evicted] = DB::transaction(function () use ($bot, $bytes, $userId) {
            $fresh = $this->lock($bot);

            if ($fresh === null) {
                return [null, []];
            }

            $file = $this->files->storeContent(
                $bytes,
                $this->fileName($fresh, 'candidate', 'png'),
                'image/png',
                $fresh,
                // The worker has no auth(): attribute the file to whoever asked for the generation,
                // so it is not left creator-less (HasCreator keeps an explicit id as given).
                $userId !== null ? ['uploader_id' => $userId] : [],
            );

            $identity = $this->identityOf($fresh);
            $identity['candidates'][] = $file->getKey();

            $evicted = $this->trimCandidates($identity);

            $this->writeIdentity($fresh, $identity);

            return [$file, $evicted];
        });

        foreach ($evicted as $id) {
            $this->deleteOwnedFile($bot, $id);
        }

        return $file;
    }

    // ---- Curation --------------------------------------------------------------------

    /** Promote a candidate to the APPROVED likeness. It stays in the strip (and becomes un-evictable). */
    public function approve(Bot $bot, File $file): Bot
    {
        return DB::transaction(function () use ($bot, $file) {
            $fresh = $this->lock($bot) ?? $bot;
            $identity = $this->identityOf($fresh);

            $this->assertCandidate($identity, $file);

            $identity['canonical_file_id'] = $file->getKey();

            $this->writeIdentity($fresh, $identity);

            return $fresh;
        });
    }

    /**
     * Drop a candidate and delete its bytes. The APPROVED likeness is refused — removing it as a
     * side effect of tidying the strip would silently un-identify the bot; clear the approval with a
     * normal bot save first (`visual.canonical_file_id = null`), then delete.
     */
    public function removeCandidate(Bot $bot, File $file): Bot
    {
        $fresh = DB::transaction(function () use ($bot, $file) {
            $fresh = $this->lock($bot) ?? $bot;
            $identity = $this->identityOf($fresh);

            $this->assertCandidate($identity, $file);

            if ($identity['canonical_file_id'] === $file->getKey()) {
                throw ValidationException::withMessages(['file' => [__('bot.visual.canonical_locked')]]);
            }

            $identity['candidates'] = array_values(array_filter(
                $identity['candidates'],
                fn (string $id) => $id !== $file->getKey(),
            ));

            // A candidate can also be the (re)generation source — clear that pointer with it.
            $this->detachPointers($identity, $file->getKey());

            $this->writeIdentity($fresh, $identity);

            return $fresh;
        });

        $this->deleteOwnedFile($bot, $file->getKey());

        return $fresh;
    }

    // ---- Prompt ----------------------------------------------------------------------

    /**
     * Compose the provider prompt from the PERSISTED identity plus this run's one-off instruction.
     *
     * Order is the point: the subject first, then the outfit, then the look. The WARDROBE is a
     * first-class line because it is the only steerable defence against the provider's output-side
     * moderation — the same character comes back refused as unsafe in one outfit and fine in
     * another — so an identity that names its clothes is the difference between a usable generator
     * and a wall. In reference mode the source image is anchored explicitly, so the edit changes the
     * styling rather than the person.
     *
     * Empty in every part is a 422: there is nothing to draw, and a blank prompt would burn a
     * provider call to find that out.
     */
    public function composePrompt(Bot $bot, BotVisualGenerationDTO $dto): string
    {
        $identity = $this->identityOf($bot);

        $lines = [];

        if ($dto->mode->usesReference()) {
            $lines[] = 'Keep the person from the source image: the same face, hair and body type.';
        }

        if ($identity['descriptor'] !== null) {
            $lines[] = 'Subject: ' . $identity['descriptor'];
        }

        if ($identity['wardrobe'] !== null) {
            $lines[] = 'Wardrobe: ' . $identity['wardrobe'];
        }

        if ($identity['aesthetic'] !== null) {
            $lines[] = 'Style: ' . $identity['aesthetic'];
        }

        if ($identity['prohibitions'] !== []) {
            $lines[] = 'Never show: ' . implode(', ', $identity['prohibitions']);
        }

        if ($dto->instruction !== null) {
            $lines[] = $dto->instruction;
        }

        // A reference anchor alone describes nothing — require real identity material.
        $described = array_filter([
            $identity['descriptor'],
            $identity['wardrobe'],
            $identity['aesthetic'],
            $dto->instruction,
        ]);

        if ($described === []) {
            throw ValidationException::withMessages(['instruction' => [__('bot.visual.nothing_to_generate')]]);
        }

        return implode("\n", $lines);
    }

    // ---- Files -----------------------------------------------------------------------

    /**
     * Resolve a file reference to a LIVE file of the bot's workspace, or null.
     *
     * The tenant boundary is pinned to the BOT, not to the ambient context: {@see WorkspaceScope}
     * only adds its predicate while a shared workspace is active and is a documented no-op without
     * one (queue, console), so an ambient-only lookup would run UNCONSTRAINED off the request path
     * and could resolve a foreign file. In own-database mode the bot carries no `workspace_id`
     * (tenant tables omit it) and the dedicated connection IS the boundary, so the predicate is
     * added only when the row actually has one. The id is uuid-shape-checked before the query.
     */
    public function resolveFile(Bot $bot, ?string $id): ?File
    {
        if ($id === null || !Str::isUuid($id)) {
            return null;
        }

        return $this->fileQuery($bot)->find($id);
    }

    /**
     * The APPROVED likeness's raw bytes, or null when the bot has no approved likeness (or its file /
     * blob has since gone). The read a CONSUMER of the identity makes — today the generation-session
     * delegation, which COPIES these bytes into the session so the run keeps drawing the same person
     * after the strip is re-curated.
     *
     * Fail-SOFT by design: a missing approval, a deleted file or an unreadable blob all answer null, and
     * the caller simply proceeds without a character reference. The alternative — throwing — would turn a
     * tidy-up in the visual module into a hard failure of an unrelated delegation.
     *
     * The bytes are content: never logged.
     */
    public function canonicalImageBytes(Bot $bot): ?string
    {
        $identity = $bot->visualIdentity();
        $file = $this->resolveFile($bot, $identity['canonical_file_id'] ?? null);

        if ($file === null || !is_string($file->path) || $file->path === '') {
            return null;
        }

        $bytes = Storage::exists($file->path) ? Storage::get($file->path) : null;

        return $bytes === null || $bytes === '' ? null : $bytes;
    }

    /** The bytes the provider starts from — null in description mode (there is no source). */
    private function sourceBytes(Bot $bot, BotVisualGenerationDTO $dto): ?string
    {
        if (!$dto->mode->usesReference()) {
            return null;
        }

        if ($dto->reference !== null) {
            return $dto->reference->get();
        }

        $file = $this->resolveFile($bot, $dto->referenceFileId);

        if ($file === null) {
            throw ValidationException::withMessages(['reference_file_id' => [__('bot.visual.invalid_file')]]);
        }

        $bytes = Storage::get((string) $file->path);

        if ($bytes === null || $bytes === '') {
            throw ValidationException::withMessages(['reference_file_id' => [__('bot.visual.reference_unreadable')]]);
        }

        return $bytes;
    }

    /** Permanently delete a file (row + bytes) — but only if the BOT owns it. */
    private function deleteOwnedFile(Bot $bot, ?string $id): void
    {
        $file = $this->resolveFile($bot, $id);

        if ($file === null || !$this->isOwnedBy($bot, $file)) {
            return;
        }

        $this->files->forceDelete($file);
    }

    /**
     * Delete a superseded bot-owned file, unless the identity still POINTS at it — including as the
     * incoming reference itself: re-generating from the very file that is already the source
     * ("iterate on this one again") passes the same id in as both previous and new, and deleting it
     * would destroy the source the module still names.
     */
    private function forgetOwnedFile(Bot $bot, ?string $id, array $identity): void
    {
        if ($id === null
            || $id === $identity['reference_file_id']
            || $id === $identity['canonical_file_id']
            || in_array($id, $identity['candidates'], true)) {
            return;
        }

        $this->deleteOwnedFile($bot, $id);
    }

    /**
     * Drop every pointer the identity holds to a file that is about to be deleted, so removing an
     * image can never leave the module naming bytes that no longer exist. The approved likeness is
     * protected from deletion elsewhere, so in practice this clears the source pointer.
     */
    private function detachPointers(array &$identity, string $fileId): void
    {
        if ($identity['reference_file_id'] === $fileId) {
            $identity['reference_file_id'] = null;
        }

        if ($identity['canonical_file_id'] === $fileId) {
            $identity['canonical_file_id'] = null;
        }
    }

    private function isOwnedBy(Bot $bot, File $file): bool
    {
        return $file->fileable_type === $bot->getMorphClass()
            && $file->fileable_id === $bot->getKey();
    }

    private function fileQuery(Bot $bot): Builder
    {
        $query = File::query()->whereNull('disk_trashed_at');

        $workspaceId = $bot->getAttribute(WorkspaceScope::COLUMN);

        if (is_string($workspaceId) && $workspaceId !== '') {
            $query->where($query->getModel()->qualifyColumn(WorkspaceScope::COLUMN), $workspaceId);
        }

        return $query;
    }

    /** A stable, non-secret file name (never the prompt — that is user content). */
    private function fileName(Bot $bot, string $kind, string $extension): string
    {
        return 'bot-' . $kind . '-' . Str::limit((string) $bot->getKey(), 8, '') . '-' . now()->format('Ymd-His') . '.' . $extension;
    }

    // ---- Identity state --------------------------------------------------------------

    /** The bot's identity in its normalized shape, or an empty module when it has none yet. */
    private function identityOf(Bot $bot): array
    {
        return $bot->visualIdentity() ?? [
            // Generating an image never flips the user's own module toggle.
            'enabled' => false,
            'descriptor' => null,
            'aesthetic' => null,
            'wardrobe' => null,
            'prohibitions' => [],
            'reference_file_id' => null,
            'candidates' => [],
            'canonical_file_id' => null,
            'prompt' => null,
        ];
    }

    private function writeIdentity(Bot $bot, array $identity): void
    {
        $bot->update(['visual' => $identity]);
    }

    /** The bot row, locked for the duration of the transaction; null when it vanished mid-run. */
    private function lock(Bot $bot): ?Bot
    {
        return Bot::query()->whereKey($bot->getKey())->lockForUpdate()->first();
    }

    /**
     * Evict the oldest UNAPPROVED candidates until the strip fits, returning what was dropped.
     *
     * @return array<int, string>
     */
    private function trimCandidates(array &$identity): array
    {
        $evicted = [];

        while (count($identity['candidates']) > self::MAX_CANDIDATES) {
            $index = null;

            foreach ($identity['candidates'] as $position => $id) {
                if ($id !== $identity['canonical_file_id']) {
                    $index = $position;
                    break;
                }
            }

            // Only the approved one left (it cannot exceed the cap on its own) — stop rather than spin.
            if ($index === null) {
                break;
            }

            $victim = $identity['candidates'][$index];

            $evicted[] = $victim;
            unset($identity['candidates'][$index]);
            $identity['candidates'] = array_values($identity['candidates']);

            // Its bytes are about to go — the module must not keep naming them.
            $this->detachPointers($identity, $victim);
        }

        return $evicted;
    }

    private function assertCandidate(array $identity, File $file): void
    {
        if (!in_array($file->getKey(), $identity['candidates'], true)) {
            throw ValidationException::withMessages(['file' => [__('bot.visual.not_a_candidate')]]);
        }
    }
}
