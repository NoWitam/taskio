<?php

namespace App\Markdown\Tree\Nodes;

final class OrderedListNode extends AbstractNode
{
    public const TYPE = 'orderedList';

    /**
     * @param  MarkdownNode[]  $content
     */
    public function __construct(
        public readonly array $content = [],
        public readonly int $start = 1
    ) {
    }

    public static function type(): string
    {
        return self::TYPE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        self::assertType($payload);
        $attrs = is_array($payload['attrs'] ?? null) ? $payload['attrs'] : [];
        return new self(
            self::buildChildren($payload['content'] ?? []),
            (int) ($attrs['start'] ?? 1)
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => ['start' => $this->start],
            'content' => array_map(static fn (MarkdownNode $node) => $node->toArray(), $this->content),
        ];
    }
}
