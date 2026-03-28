<?php

namespace App\Markdown\Tree\Nodes;

use App\Markdown\Tree\MarkdownTree;
use InvalidArgumentException;
use JsonSerializable;

final class IfBranch implements JsonSerializable
{
    private const ALLOWED_KINDS = ['if', 'else-if', 'else'];

    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly MarkdownTree $content,
        public readonly ?IfCondition $condition = null
    ) {
        if (!in_array($this->kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException('Invalid IF branch kind: '.$this->kind);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $id = (string) ($payload['id'] ?? uniqid('branch_', true));
        $kind = (string) ($payload['kind'] ?? 'if');
        $contentPayload = is_array($payload['content'] ?? null) ? $payload['content'] : null;
        $content = MarkdownTree::fromArray($contentPayload ?? MarkdownTree::emptyDocumentArray());
        $condition = isset($payload['condition']) && is_array($payload['condition'])
            ? IfCondition::fromArray($payload['condition'])
            : null;

        // For ELSE branches condition must be null.
        if ($kind === 'else') {
            $condition = null;
        }

        return new self($id, $kind, $content, $condition);
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'kind' => $this->kind,
            'content' => $this->content->toArray(),
        ];

        if ($this->condition) {
            $data['condition'] = $this->condition->toArray();
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
