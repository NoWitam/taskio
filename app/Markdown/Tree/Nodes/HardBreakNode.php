<?php

namespace App\Markdown\Tree\Nodes;

final class HardBreakNode extends AbstractNode
{
    public const TYPE = 'hardBreak';

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
        return new self();
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE];
    }
}
