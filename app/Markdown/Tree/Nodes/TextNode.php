<?php

namespace App\Markdown\Tree\Nodes;

final class TextNode extends AbstractNode
{
    public const TYPE = 'text';

    /**
     * @param  TextMark[]  $marks
     */
    public function __construct(
        public readonly string $text,
        public readonly array $marks = []
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
        $text = (string) ($payload['text'] ?? '');
        $marksPayload = is_array($payload['marks'] ?? null) ? $payload['marks'] : [];
        $marks = array_values(array_map(
            static fn (array $mark) => TextMark::fromPayload($mark),
            array_filter($marksPayload, static fn ($mark) => is_array($mark) && isset($mark['type']))
        ));

        return new self($text, $marks);
    }

    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'text' => $this->text,
        ];

        if (!empty($this->marks)) {
            $data['marks'] = array_map(static fn (TextMark $mark) => $mark->toPayload(), $this->marks);
        }

        return $data;
    }
}
