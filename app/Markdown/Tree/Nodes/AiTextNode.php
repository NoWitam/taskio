<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class AiTextNode extends AbstractNode
{
    public const TYPE = 'aiText';

    /**
     * @param  string[]  $labels
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $personaId,
        public readonly string $prompt,
        public readonly array $labels = []
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

        foreach (['id', 'prompt'] as $required) {
            if (!array_key_exists($required, $attrs)) {
                throw new InvalidArgumentException(sprintf('AI text node missing "%s" attribute.', $required));
            }
        }

        return new self(
            (string) $attrs['id'],
            isset($attrs['personaId']) ? (string) $attrs['personaId'] : null,
            (string) $attrs['prompt'],
            array_values(is_array($attrs['labels'] ?? null) ? array_map('strval', $attrs['labels']) : []),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => [
                'id' => $this->id,
                'personaId' => $this->personaId,
                'prompt' => $this->prompt,
                'labels' => $this->labels,
            ],
        ];
    }
}
