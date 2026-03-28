<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;
use JsonException;

abstract class AbstractNode implements MarkdownNode
{
    abstract public static function type(): string;

    public function toJson(int $options = 0): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $options);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode markdown node to JSON: '.$exception->getMessage(), 0, $exception);
        }
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected static function assertType(array $payload): void
    {
        $type = $payload['type'] ?? null;
        if ($type !== static::type()) {
            throw new InvalidArgumentException(sprintf('Invalid node type "%s", expected "%s".', (string) $type, static::type()));
        }
    }

    /**
     * @template T of MarkdownNode
     * @param  array<int, array<string, mixed>>|null  $content
     * @return array<int, MarkdownNode>
     */
    protected static function buildChildren(?array $content): array
    {
        if (!is_array($content) || empty($content)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $child) => NodeFactory::make($child),
            array_filter($content, static fn ($value) => is_array($value))
        ));
    }
}
