<?php

namespace App\Markdown\Tree;

use App\Markdown\Tree\Nodes\MarkdownNode;
use App\Markdown\Tree\Nodes\NodeFactory;
use App\Markdown\Tree\Nodes\ParagraphNode;
use App\Markdown\Tree\Nodes\TextNode;
use JsonException;
use JsonSerializable;
use Stringable;
use InvalidArgumentException;

final class MarkdownTree implements JsonSerializable, Stringable
{
    /**
     * @param  MarkdownNode[]  $content
     */
    public function __construct(private array $content = [])
    {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Provide canonical empty doc array for helper classes.
     *
     * @return array<string, mixed>
     */
    public static function emptyDocumentArray(): array
    {
        return [
            'type' => 'doc',
            'content' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromArray(?array $payload): self
    {
        if (!is_array($payload)) {
            return self::empty();
        }

        if (($payload['type'] ?? null) !== 'doc') {
            throw new InvalidArgumentException('Markdown tree root node must be of type "doc".');
        }

        $contentRows = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $content = array_values(array_map(
            static fn (array $node) => NodeFactory::make($node),
            array_filter($contentRows, static fn ($row) => is_array($row))
        ));

        return new self($content);
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Markdown tree JSON is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Markdown tree JSON must decode into an array.');
        }

        return self::fromArray($decoded);
    }

    public static function fromPlainText(string $text): self
    {
        return new self([
            new ParagraphNode([
                new TextNode($text),
            ]),
        ]);
    }

    public function withContent(array $content): self
    {
        return new self($content);
    }

    /**
     * @return MarkdownNode[]
     */
    public function getContent(): array
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'doc',
            'content' => array_map(static fn (MarkdownNode $node) => $node->toArray(), $this->content),
        ];
    }

    public function toJson(int $options = 0): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $options);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode markdown tree: '.$exception->getMessage(), 0, $exception);
        }
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toJson();
    }
}
