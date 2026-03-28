<?php

namespace App\Markdown\Tree\Nodes;

use JsonSerializable;

interface MarkdownNode extends JsonSerializable
{
    /**
     * Build node from normalized array payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self;

    /**
     * Convert node back to array representation understood by the editor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Helper shortcut for encoding to JSON.
     */
    public function toJson(int $options = 0): string;
}
