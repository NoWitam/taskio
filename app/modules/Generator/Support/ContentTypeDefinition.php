<?php

namespace App\Modules\Generator\Support;

/**
 * A CONTENT TYPE — the RECIPE shape a Template fills: a stable `id` (the wire id stored in
 * `templates.content_type` + mirrored by the FE), a human `label`, and an ORDERED list of typed
 * {@see ContentTypePart}s. The parts drive EVERYTHING data-driven — the editor sections, the per-part
 * content validation, and the per-part render — so a content type carries NO behavior of its own (all
 * behavior lives on each part's {@see \App\Modules\Generator\Enums\PartKind}, D8).
 *
 * Code-defined in the {@see \App\Modules\Generator\Services\ContentTypeRegistry} for v1 (D1). A future
 * user-created content type is just another instance of this VO (its parts recombine the SAME kinds),
 * so it UNIONs into the registry with zero rework to the validators/renderer.
 */
final class ContentTypeDefinition
{
    /**
     * @param  array<int, ContentTypePart>  $parts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $parts,
    ) {}

    /** The part declared under $key, or null when this type declares no such part. */
    public function part(string $key): ?ContentTypePart
    {
        foreach ($this->parts as $part) {
            if ($part->key === $key) {
                return $part;
            }
        }

        return null;
    }

    /** @return array<int, string> the declared part keys (the only keys `content` may carry). */
    public function partKeys(): array
    {
        return array_map(fn (ContentTypePart $part): string => $part->key, $this->parts);
    }

    /**
     * The stable wire shape the FE mirrors: `{id, label, parts:[{key,kind,label,required,config}]}`.
     *
     * @return array{id: string, label: string, parts: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'parts' => array_map(fn (ContentTypePart $part): array => $part->toArray(), $this->parts),
        ];
    }
}
