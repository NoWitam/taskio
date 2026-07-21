<?php

namespace App\Modules\Disk\DTOs;

use App\Modules\Disk\Enums\FileType;
use Illuminate\Http\Request;

/**
 * The normalized filter set for the unified folder browse (`GET /disk/items`). Everything is
 * clamped to a safe default here rather than 422'd — a browse endpoint should ignore a bad filter,
 * not fail. Drives which staged-paginator stages run and how each stage is scoped/sorted.
 */
class DiskItemFilters
{
    /** search "where" scopes. */
    public const WHERE_FOLDER = 'folder';        // just this folder (default)

    public const WHERE_SUBTREE = 'subtree';      // this folder + all descendants

    public const WHERE_EVERYWHERE = 'everywhere'; // the whole disk

    /** The pseudo-type meaning "show folders" (folders are not a FileType). */
    public const FOLDER_TYPE = 'folder';

    public function __construct(
        /** @var array<int, string> concrete FileType values to show; empty = all file types */
        public readonly array $fileTypes,
        public readonly bool $includeFolders,
        public readonly bool $includeFiles,
        public readonly ?string $search,
        public readonly bool $searchDescription,
        public readonly string $where,
        public readonly string $sort,   // 'name' | 'created_at'
        public readonly string $dir,    // 'asc' | 'desc'
    ) {}

    public static function fromRequest(Request $request): self
    {
        $rawTypes = array_values(array_filter((array) $request->get('types', []), 'is_string'));
        $validFileTypes = array_map(fn (FileType $t) => $t->value, FileType::cases());
        $fileTypes = array_values(array_intersect($rawTypes, $validFileTypes));

        $typeFilterSet = $rawTypes !== [];
        // No type filter → show everything. Otherwise folders show only if 'folder' was picked, and
        // files show only if at least one real file type was picked.
        $includeFolders = !$typeFilterSet || in_array(self::FOLDER_TYPE, $rawTypes, true);
        $includeFiles = !$typeFilterSet || $fileTypes !== [];

        $where = self::clamp((string) $request->get('search_where'), [self::WHERE_FOLDER, self::WHERE_SUBTREE, self::WHERE_EVERYWHERE], self::WHERE_FOLDER);
        $sort = self::clamp((string) $request->get('sort'), ['name', 'created_at'], 'name');
        $dir = self::clamp((string) $request->get('dir'), ['asc', 'desc'], $sort === 'created_at' ? 'desc' : 'asc');

        return new self(
            fileTypes: $fileTypes,
            includeFolders: $includeFolders,
            includeFiles: $includeFiles,
            search: $request->string('q')->trim()->value() ?: null,
            searchDescription: $request->get('search_in') === 'name_description',
            where: $where,
            sort: $sort,
            dir: $dir,
        );
    }

    private static function clamp(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
