<?php

namespace App\Markdown\Tree\Nodes;

final class BlockquoteNode extends AbstractNode
{
    public const TYPE = 'blockquote';

    /**
     * @param  MarkdownNode[]  $content
     */
    public function __construct(public readonly array $content = [])
    {
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
        return new self(self::buildChildren($payload['content'] ?? []));
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'content' => array_map(static fn (MarkdownNode $node) => $node->toArray(), $this->content),
        ];
    }
}
