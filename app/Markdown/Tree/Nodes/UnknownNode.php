<?php

namespace App\Markdown\Tree\Nodes;

final class UnknownNode extends AbstractNode
{
    public const TYPE = '_unknown';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw)
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
        return new self($payload);
    }

    public function toArray(): array
    {
        return $this->raw;
    }
}
