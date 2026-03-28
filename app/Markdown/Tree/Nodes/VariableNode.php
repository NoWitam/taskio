<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class VariableNode extends AbstractNode
{
    public const TYPE = 'variable';

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly bool $locked,
        public readonly array $pipeline,
        public readonly string $resultType
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
        foreach (['id', 'name', 'type', 'resultType'] as $required) {
            if (!array_key_exists($required, $attrs)) {
                throw new InvalidArgumentException(sprintf('Variable node missing "%s" attribute.', $required));
            }
        }

        return new self(
            (string) $attrs['id'],
            (string) $attrs['name'],
            (string) $attrs['type'],
            (bool) ($attrs['locked'] ?? false),
            array_values(is_array($attrs['pipeline'] ?? null) ? $attrs['pipeline'] : []),
            (string) $attrs['resultType'],
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => [
                'id' => $this->id,
                'name' => $this->name,
                'type' => $this->type,
                'locked' => $this->locked,
                'pipeline' => $this->pipeline,
                'resultType' => $this->resultType,
            ],
        ];
    }
}
