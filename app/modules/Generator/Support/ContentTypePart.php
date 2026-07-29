<?php

namespace App\Modules\Generator\Support;

use App\Modules\Generator\Enums\PartKind;

/**
 * One PART of a content-type recipe: a stable `key` (the key its authored content lives under in the
 * Template's `content` map), a behavioral {@see PartKind}, a human `label`, a `required` flag, and a
 * free-form `config` (per-kind authoring hints — reserved for future kinds; empty for the v1 system
 * types). A plain immutable value object, built by the {@see \App\Modules\Generator\Services\ContentTypeRegistry}
 * (code-defined now; a future user-created type UNIONs into the same shape — D1).
 */
final class ContentTypePart
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly string $key,
        public readonly PartKind $kind,
        public readonly string $label,
        public readonly bool $required = true,
        public readonly array $config = [],
    ) {}

    /**
     * The stable wire shape the FE mirrors: `{key, kind, label, required, config}`. `kind` is the enum
     * VALUE (a closed string union on the FE), never the id — every behavioral switch keys on it.
     *
     * @return array{key: string, kind: string, label: string, required: bool, config: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'required' => $this->required,
            'config' => $this->config,
        ];
    }
}
