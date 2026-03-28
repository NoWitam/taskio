<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class HeadingNode extends AbstractNode
{
    public const TYPE = 'heading';

    /**
     * @param  MarkdownNode[]  $content
     */
    public function __construct(
        public readonly int $level,
        public readonly array $content = []
    ) {
        if ($this->level < 1 || $this->level > 6) {
            throw new InvalidArgumentException('Heading level must be between 1 and 6.');
        }
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
        $level = (int) ($attrs['level'] ?? 1);
        $content = self::buildChildren($payload['content'] ?? []);

        return new self($level, $content);
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => ['level' => $this->level],
            'content' => array_map(static fn (MarkdownNode $node) => $node->toArray(), $this->content),
        ];
    }
}
