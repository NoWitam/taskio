<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class AiTextNode extends AbstractNode
{
    public const TYPE = 'aiText';

    /**
     * @param  string[]  $labels
     * @param  string|null  $authorId  the block's per-block AUTHOR (an opaque id the runtime resolves to a
     *                                 voice). Appended LAST so every existing positional construction keeps
     *                                 working; null = no author, the pre-existing shape.
     * @param  string|null  $authorName  the author's name as it was at authoring time — a DISPLAY-ONLY
     *                                   snapshot the editor chip falls back to when the author no longer
     *                                   resolves (nothing server-side reads it). It rides ALONG with the id
     *                                   because a node that carries one without the other turns a single pass
     *                                   through the tree into permanent loss of the label a deleted author is
     *                                   shown under. Appended after it, for the same positional reason.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $personaId,
        public readonly string $prompt,
        public readonly array $labels = [],
        public readonly ?string $authorId = null,
        public readonly ?string $authorName = null
    ) {}

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
            isset($attrs['authorId']) ? (string) $attrs['authorId'] : null,
            isset($attrs['authorName']) ? (string) $attrs['authorName'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'attrs' => [
                'id' => $this->id,
                'personaId' => $this->personaId,
                'authorId' => $this->authorId,
                'authorName' => $this->authorName,
                'prompt' => $this->prompt,
                'labels' => $this->labels,
            ],
        ];
    }
}
