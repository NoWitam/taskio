<?php

namespace App\Modules\Generator\Services;

use App\Modules\Disk\Models\File;
use App\Modules\Generator\Exceptions\ImageBaseUnavailable;
use App\Modules\Generator\Exceptions\ImageBaseUnsupported;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves an image plan's BASE — where the image STARTS — to raw bytes (R2 sub-stage 2c). This is the
 * DELIBERATE Generator → Disk edge (Fork 3): a `disk_file`/`from_slot` base reads a Disk {@see File}'s bytes.
 * The lookup goes through the tenant-scoped model, so WorkspaceScope (shared mode) / the tenant connection
 * (own mode) make a foreign-workspace id simply NOT FOUND — a base can never read across tenants.
 *
 *   - disk_file {file:<id>}   a fixed Disk file id. Missing / foreign / non-image / blob-less → fail-soft.
 *   - from_slot {slot:<name>} a file-typed slot filled per session; its value is a file id or a
 *                             `{id,…}` descriptor. Unfilled / non-file → fail-soft.
 *   - ai_generate             text→image. The metered generate call needs the session prompt resolver, so
 *                             {@see ImageChainExecutor::execute} now INTERCEPTS this kind BEFORE calling
 *                             resolve() (see {@see ImageChainExecutor::generateBase}). The throw below is
 *                             retained only as a DEFENSIVE fallback for any DIRECT resolve() call (e.g. a
 *                             mask ref never carries an ai_generate kind) → {@see ImageBaseUnsupported}.
 *
 * Every failure is a DOMAIN exception the session executor turns into a `failed` image part with a localized,
 * non-secret message — never a crash, never the base config.
 */
class ImageBaseResolver
{
    /**
     * Resolve the base config against the session's filled slot values.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $slotValues
     * @return array{bytes: string, mime: string}
     */
    public function resolve(array $base, array $slotValues): array
    {
        return match ($base['kind'] ?? null) {
            'disk_file' => $this->readFileId($base['file'] ?? null),
            'from_slot' => $this->readFileId($this->slotFileId($base['slot'] ?? null, $slotValues)),
            'ai_generate' => throw new ImageBaseUnsupported,
            default => throw new ImageBaseUnavailable,
        };
    }

    /**
     * Resolve a mask REFERENCE (an ai_edit's optional inpainting mask) to bytes — the same file-read path as a
     * base. Absent → null; present but unresolvable → fail-soft (the user asked for a masked edit, so a broken
     * mask fails the part rather than silently editing the whole image).
     *
     * @return string|null raw mask bytes, or null when no mask was declared
     */
    public function resolveMask(mixed $mask, array $slotValues): ?string
    {
        if ($mask === null) {
            return null;
        }

        // A mask ref is a file id (string) or a {id,…}/{slot:…} descriptor — the same shapes a base file uses.
        $id = is_array($mask) && isset($mask['slot'])
            ? $this->slotFileId($mask['slot'], $slotValues)
            : $this->fileIdFrom($mask);

        return $this->readFileId($id)['bytes'];
    }

    /**
     * Read a Disk file id to bytes, workspace-scoped. Validates existence, that it is an image, and that its
     * blob is present; any miss is a fail-soft {@see ImageBaseUnavailable}.
     *
     * @return array{bytes: string, mime: string}
     */
    private function readFileId(mixed $id): array
    {
        if (!is_string($id) || $id === '') {
            throw new ImageBaseUnavailable;
        }

        // Tenant-scoped: a foreign-workspace id resolves to null (never a cross-tenant read).
        $file = File::query()->find($id);

        if ($file === null) {
            throw new ImageBaseUnavailable;
        }

        $mime = (string) $file->mime_type;

        if (!str_starts_with($mime, 'image/')) {
            throw new ImageBaseUnavailable;
        }

        if (!Storage::exists($file->path)) {
            throw new ImageBaseUnavailable;
        }

        $bytes = Storage::get($file->path);

        if ($bytes === null || $bytes === '') {
            throw new ImageBaseUnavailable;
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** The Disk file id held by a file-typed slot value (a bare id or a `{id,…}` descriptor), or null. */
    private function slotFileId(mixed $name, array $slotValues): ?string
    {
        if (!is_string($name) || $name === '') {
            return null;
        }

        return $this->fileIdFrom($slotValues[$name] ?? null);
    }

    /** Coerce a file reference (id string or `{id,…}` descriptor) to its id, or null. */
    private function fileIdFrom(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value)) {
            $id = $value['id'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }
}
