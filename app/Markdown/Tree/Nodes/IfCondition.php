<?php

namespace App\Markdown\Tree\Nodes;

use JsonSerializable;

final class IfCondition implements JsonSerializable
{
    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     */
    public function __construct(
        public readonly string $variableId,
        public readonly array $pipeline = [],
        public readonly string $resultType = 'boolean'
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            (string) ($payload['variableId'] ?? ''),
            array_values(is_array($payload['pipeline'] ?? null) ? $payload['pipeline'] : []),
            (string) ($payload['resultType'] ?? 'boolean'),
        );
    }

    public function toArray(): array
    {
        return [
            'variableId' => $this->variableId,
            'pipeline' => $this->pipeline,
            'resultType' => $this->resultType,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
