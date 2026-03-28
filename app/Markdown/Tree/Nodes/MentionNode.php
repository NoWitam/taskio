<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class MentionNode extends AbstractNode
{
    public const TYPE = 'mention';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $avatar = null,
        public readonly ?string $email = null
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

        if (!isset($attrs['id'], $attrs['name'])) {
            throw new InvalidArgumentException('Mention node requires id and name attributes.');
        }

        return new self(
            (string) $attrs['id'],
            (string) $attrs['name'],
            isset($attrs['avatar']) ? (string) $attrs['avatar'] : null,
            isset($attrs['email']) ? (string) $attrs['email'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => array_filter([
                'id' => $this->id,
                'name' => $this->name,
                'avatar' => $this->avatar,
                'email' => $this->email,
            ], static fn ($value) => $value !== null),
        ];
    }
}
