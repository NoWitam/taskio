<?php

namespace Tests\Unit\Markdown;

use App\Markdown\Tree\MarkdownTree;
use App\Markdown\Tree\Nodes\AiTextNode;
use PHPUnit\Framework\TestCase;

/**
 * The TREE representation of an `@[ai-text]` block (the shape a {@see \App\Casts\MarkdownTreeCast} column
 * round-trips) must carry the block's per-block AUTHOR whole: both the `authorId` the runtime resolves to a
 * voice AND the `authorName` SNAPSHOT the editor chip renders when that author is gone.
 *
 * REACH TODAY IS ZERO — the cast is used only by Task and Comment, and ai-text blocks are enabled only in
 * the Generator + Workflows, which persist RAW markdown rather than trees. This pins the node for the day
 * that changes: a node that silently drops an attribute turns one pass through the tree into permanent data
 * loss, and the loss would show up far from here (a chip labelling a deleted author "Unknown author" with no
 * way back), which is exactly the kind of defect nobody traces to a missing array key.
 */
class AiTextNodeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(array $attrs = []): array
    {
        return [
            'type' => 'aiText',
            'attrs' => [
                'id' => 'ai_1',
                'personaId' => 'neutral',
                'authorId' => '019fb009-8ac1-70e8-ba1e-ed92b82a68a2',
                'authorName' => 'Marketing Maven',
                'prompt' => 'write the hook',
                'labels' => ['tone'],
            ] + $attrs,
        ];
    }

    public function test_the_author_survives_a_round_trip_whole(): void
    {
        $node = AiTextNode::fromArray($this->payload());

        $this->assertSame('019fb009-8ac1-70e8-ba1e-ed92b82a68a2', $node->authorId);
        $this->assertSame('Marketing Maven', $node->authorName);
        $this->assertSame($this->payload()['attrs'], $node->toArray()['attrs']);
    }

    /** The same, through a whole document — the actual path a cast takes on read + write. */
    public function test_a_document_round_trip_preserves_the_author_name_snapshot(): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [$this->payload()]],
        ]];

        $this->assertSame($document, MarkdownTree::fromArray($document)->toArray());
    }

    /**
     * A block with NO author (every block written before the feature, and every one whose author box is
     * empty) reads as null on BOTH halves — never as an empty string, which would look like an author whose
     * name happens to be blank.
     */
    public function test_a_block_without_an_author_reads_as_null_on_both_halves(): void
    {
        $node = AiTextNode::fromArray([
            'type' => 'aiText',
            'attrs' => ['id' => 'ai_1', 'prompt' => 'write the hook'],
        ]);

        $this->assertNull($node->authorId);
        $this->assertNull($node->authorName);
        $this->assertNull($node->toArray()['attrs']['authorName']);
    }
}
