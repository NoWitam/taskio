<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class IfBlockNode extends AbstractNode
{
    public const TYPE = 'ifBlock';

    /**
     * @param  IfBranch[]  $branches
     */
    public function __construct(
        public readonly string $id,
        public readonly array $branches = []
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
        $branchesPayload = is_array($attrs['branches'] ?? null) ? $attrs['branches'] : [];

        $branches = array_values(array_map(
            static fn ($branch) => IfBranch::fromArray(is_array($branch) ? $branch : []),
            array_filter($branchesPayload, static fn ($branch) => is_array($branch))
        ));

        if (empty($branches)) {
            throw new InvalidArgumentException('IF block requires at least one branch.');
        }

        $id = (string) ($attrs['id'] ?? uniqid('if_', true));

        return new self($id, $branches);
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => [
                'id' => $this->id,
                'branches' => array_map(static fn (IfBranch $branch) => $branch->toArray(), $this->branches),
            ],
        ];
    }
}
