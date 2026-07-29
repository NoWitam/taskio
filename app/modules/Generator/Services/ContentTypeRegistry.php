<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\PartKind;
use App\Modules\Generator\Support\ContentTypeDefinition;
use App\Modules\Generator\Support\ContentTypePart;

/**
 * The code-defined CONTENT TYPE registry (D1) — the single authority for the system content types a
 * Template can be a recipe for. It holds the definitions as DATA (like the const / function registries,
 * no `content_types` table now); a future user-created-type table would simply UNION its rows into
 * {@see all()} — the validators + renderer are kind-driven (D8), so that costs them zero rework.
 *
 * The three v1 system types:
 *   - `post`             = [text_body]                  a plain text post.
 *   - `post_with_image`  = [text_body, image_plan]      a post + one image media plan.
 *   - `video_script`     = [shot_list, storyboard]      a STRUCTURED short-video shot list (hook / shots /
 *                                                       cta) + one AI image per shot (Phase B rework).
 *
 * LEGACY parts (script, scene_plan) are no longer authored — Phase B DROPPED them from the video_script
 * composition — but existing session snapshots still reference them, so {@see legacyParts} keeps them
 * renderable/refinable from a snapshot without offering them for new authoring.
 *
 * Every part's KIND is drawn from the closed {@see PartKind} set; ALL behavior keys on the kind, never
 * on the content-type id, so recombining these kinds into a new type is a pure-data change.
 */
class ContentTypeRegistry
{
    /**
     * The system content-type definitions, in display order. Built fresh per call (plain VOs, no state)
     * — cheap, and a future DB-backed union would merge stored rows in here.
     *
     * @return array<int, ContentTypeDefinition>
     */
    public function all(): array
    {
        return [
            new ContentTypeDefinition('post', 'Post', [
                new ContentTypePart('body', PartKind::TEXT_BODY, 'Post body', true),
            ]),
            new ContentTypeDefinition('post_with_image', 'Post with image', [
                new ContentTypePart('body', PartKind::TEXT_BODY, 'Post body', true),
                new ContentTypePart('image', PartKind::IMAGE_PLAN, 'Image', false),
            ]),
            new ContentTypeDefinition('video_script', 'Video script', [
                new ContentTypePart('shot_list', PartKind::SHOT_LIST, 'Shot list', true),
                new ContentTypePart('storyboard', PartKind::STORYBOARD, 'Storyboard', false),
            ]),
        ];
    }

    /**
     * The LEGACY, no-longer-authored parts (Phase B dropped script + scene_plan from the video_script
     * composition). NOT in {@see all()} (the editor never offers them), but keyed here so an EXISTING
     * session snapshot that still carries `script` / `scene_plan` content resolves to a real part and keeps
     * rendering exactly what it was snapshotted with. This is the ONLY place their part shape survives.
     *
     * @return array<string, ContentTypePart>
     */
    private function legacyParts(): array
    {
        return [
            'script' => new ContentTypePart('script', PartKind::SCRIPT, 'Script', true),
            'scene_plan' => new ContentTypePart('scene_plan', PartKind::SCENE_PLAN, 'Scene plan', false),
        ];
    }

    /**
     * The ORDERED parts to render for a SESSION SNAPSHOT — SNAPSHOT-AUTHORITATIVE, so a registry
     * recomposition (Phase B: video_script script/scene_plan → shot_list/storyboard) can never change what
     * an already-created session renders. A NEW-style snapshot (its content carries only currently-declared
     * part keys) renders the registry definition's parts. A LEGACY snapshot (its content carries a key no
     * longer declared — an old video_script's `script`/`scene_plan`) instead renders exactly its stored
     * content keys, each mapped to its part via the definition ∪ {@see legacyParts} — so old sessions keep
     * rendering script/scene_plan and never spuriously gain shot_list/storyboard.
     *
     * @param  array<string, mixed>  $content  the snapshot's per-part authored content map
     * @return array<int, ContentTypePart>
     */
    public function partsForSnapshot(string $contentType, array $content): array
    {
        $definition = $this->find($contentType);

        if ($definition === null) {
            return [];
        }

        $legacy = $this->legacyParts();
        $hasLegacyContent = array_intersect(array_keys($content), array_keys($legacy)) !== [];

        if (!$hasLegacyContent) {
            return $definition->parts;
        }

        $parts = [];

        foreach (array_keys($content) as $key) {
            $part = $definition->part((string) $key) ?? ($legacy[(string) $key] ?? null);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /** The snapshot part under $partKey (definition ∪ legacy, snapshot-authoritative), or null. */
    public function partForSnapshot(string $contentType, array $content, string $partKey): ?ContentTypePart
    {
        foreach ($this->partsForSnapshot($contentType, $content) as $part) {
            if ($part->key === $partKey) {
                return $part;
            }
        }

        return null;
    }

    /** The definition for $id, or null when no such content type exists. */
    public function find(string $id): ?ContentTypeDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<int, string> the wire ids (for the request `in:` rule + the FE union). */
    public function ids(): array
    {
        return array_map(fn (ContentTypeDefinition $definition): string => $definition->id, $this->all());
    }

    /**
     * The keys of the parts declared BEFORE $partKey in $contentTypeId, in declared order — the cross-part
     * context scope (Phase A): a part may reference ONLY earlier parts, so this is the EARLIER-ONLY set the
     * catalog offers as `parts.<key>` and the write-validator accepts. An unknown content type, an unknown
     * part key, or the FIRST part all yield an empty list (nothing earlier to reference → no `parts.*`).
     *
     * @return array<int, string>
     */
    public function partKeysBefore(string $contentTypeId, string $partKey): array
    {
        $definition = $this->find($contentTypeId);

        if ($definition === null) {
            return [];
        }

        $keys = $definition->partKeys();
        $index = array_search($partKey, $keys, true);

        return $index === false ? [] : array_slice($keys, 0, $index);
    }
}
