<?php

namespace App\Markdown\Tree\Nodes;

use InvalidArgumentException;

final class NodeFactory
{
    /**
     * @var array<string, class-string<MarkdownNode>>
     */
    private const MAP = [
        ParagraphNode::TYPE => ParagraphNode::class,
        HeadingNode::TYPE => HeadingNode::class,
        TextNode::TYPE => TextNode::class,
        MentionNode::TYPE => MentionNode::class,
        VariableNode::TYPE => VariableNode::class,
        AiTextNode::TYPE => AiTextNode::class,
        IfBlockNode::TYPE => IfBlockNode::class,
        BulletListNode::TYPE => BulletListNode::class,
        OrderedListNode::TYPE => OrderedListNode::class,
        ListItemNode::TYPE => ListItemNode::class,
        BlockquoteNode::TYPE => BlockquoteNode::class,
        HardBreakNode::TYPE => HardBreakNode::class,
        HorizontalRuleNode::TYPE => HorizontalRuleNode::class,
        CodeBlockNode::TYPE => CodeBlockNode::class,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(array $payload): MarkdownNode
    {
        $type = $payload['type'] ?? null;
        $class = self::MAP[(string) $type] ?? UnknownNode::class;

        if (!is_subclass_of($class, MarkdownNode::class)) {
            throw new InvalidArgumentException(sprintf('Node class %s must implement %s', $class, MarkdownNode::class));
        }

        return $class::fromArray($payload);
    }
}
