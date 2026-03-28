<?php

namespace App\Markdown\Tree\Nodes;

use App\Markdown\Tree\Nodes\MarkdownNode;

final class CodeBlockNode extends AbstractNode
{
    public const TYPE = 'codeBlock';

    /**
     * @param  MarkdownNode[]  $content
     */
    public function __construct(
        public readonly array $content = [],
        public readonly ?string $language = null
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

        return new self(
            self::buildChildren($payload['content'] ?? []),
            isset($attrs['language']) ? (string) $attrs['language'] : null
        );
    }

    public function toArray(): array
    {
        $data = [
            'type' => self::TYPE,
            'content' => array_map(static fn (MarkdownNode $node) => $node->toArray(), $this->content),
        ];

        if ($this->language) {
            $data['attrs'] = ['language' => $this->language];
        }

        return $data;
    }
}
