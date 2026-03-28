<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;
use JsonSerializable;

enum TextMark: string implements JsonSerializable
{
    case Bold = 'bold';
    case Italic = 'italic';
    case Underline = 'underline';
    case Link = 'link';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $type = $payload['type'] ?? null;
        return match ($type) {
            self::Bold->value => self::Bold,
            self::Italic->value => self::Italic,
            self::Underline->value => self::Underline,
            self::Link->value => self::Link,
            default => throw new InvalidArgumentException(sprintf('Unsupported text mark type "%s".', (string) $type)),
        };
    }

    public function toPayload(): array
    {
        return ['type' => $this->value];
    }

    public function jsonSerialize(): array
    {
        return $this->toPayload();
    }
}
