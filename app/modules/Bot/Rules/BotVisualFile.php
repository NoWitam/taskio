<?php

namespace App\Modules\Bot\Rules;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Bot\Models\Bot;
use App\Modules\Disk\Models\File;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * OWNERSHIP rule for the file ids carried by the visual module — not a shape check.
 *
 * A raw `exists:files,id` would be wrong twice over: it bypasses Eloquent's global scopes (the
 * cross-workspace leak {@see \App\Rules\ScopedExists} exists to close) AND it would happily accept
 * ANY file in the workspace as this bot's approved likeness. So the reference is resolved through
 * the model and its CONTAINER is checked:
 *
 *   - candidates / canonical  MUST be owned by THIS bot (`fileable_type = 'bot'`, `fileable_id` =
 *     the bot). They are produced by the identity generator, which files them under the bot; a
 *     file the bot does not own can never have been one of its iterations.
 *   - the reference additionally accepts a DISK-NATIVE file ({@see File::FOLDER_TYPE}) from the
 *     workspace — "pick from Disk" is an explicit source for a (re)generation, and that file
 *     legitimately belongs to the disk, not to the bot.
 *
 * On CREATE there is no bot yet, so nothing can be bot-owned: candidates/canonical are refused
 * outright (correctly — they cannot exist before the bot does) while a disk reference still passes.
 *
 * Tenancy: the lookup pins the SESSION-style explicit predicate — the bot's own `workspace_id` when
 * it carries one (shared db_mode), otherwise the tenant connection IS the boundary (own db_mode,
 * where tenant tables omit the column). Disk-trashed and soft-deleted rows never resolve.
 */
class BotVisualFile implements ValidationRule
{
    public function __construct(
        private ?Bot $bot,
        private bool $allowDiskNative = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The column is a uuid: check the shape BEFORE querying so garbage is a clean refusal
        // rather than a database error.
        if (!is_string($value) || !Str::isUuid($value)) {
            $fail(__('bot.visual.invalid_file'));

            return;
        }

        $file = $this->query()->find($value);

        if ($file === null || !$this->isAcceptable($file)) {
            $fail(__('bot.visual.invalid_file'));
        }
    }

    /** Owned by this bot — or, for the reference, a disk-native file of the workspace. */
    private function isAcceptable(File $file): bool
    {
        if ($this->bot !== null
            && $file->fileable_type === $this->bot->getMorphClass()
            && $file->fileable_id === $this->bot->getKey()) {
            return true;
        }

        return $this->allowDiskNative && $file->fileable_type === File::FOLDER_TYPE;
    }

    /** Live files only, pinned to the bot's workspace when the row carries one. */
    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        $query = File::query()->whereNull('disk_trashed_at');

        $workspaceId = $this->bot?->getAttribute(WorkspaceScope::COLUMN);

        if (is_string($workspaceId) && $workspaceId !== '') {
            $query->where($query->getModel()->qualifyColumn(WorkspaceScope::COLUMN), $workspaceId);
        }

        return $query;
    }
}
