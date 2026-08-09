<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Models\Bot;
use App\Modules\Knowledge\DTOs\CompiledKnowledge;
use App\Modules\Knowledge\Services\KnowledgeBindingService;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Str;

/**
 * The Bot module's side of the Bot → Knowledge edge: read the base this bot is bound to, for THIS task.
 *
 * ------------------------------------------------------------------------------------------------
 * WHICH SIDE KNOWS WHAT
 *
 * Knowledge is the lower shared layer and may not name this module, so the seam is inverted: everything
 * crossing it is a PRIMITIVE. This service hands over the morph alias `'bot'`, a uuid, and — for retrieval
 * — a plain query string it composed itself. Knowledge hands back a compiled block. Neither side holds the
 * other's model.
 *
 * Composing the query HERE is the load-bearing half of that split. Knowledge cannot write it: "what is
 * this bot trying to do" is a question about tasks, forms and a conversation, three things the Knowledge
 * module deliberately knows nothing about. If it could write the query it would have to know them, and the
 * base would stop being reusable by anything that is not a task.
 *
 * ------------------------------------------------------------------------------------------------
 * NO BINDING IS NOT AN ERROR
 *
 * A bot with no binding gets NULL, and the caller keeps using the bot's own `knowledge` JSON module
 * exactly as before — byte for byte, pinned by a test. That is what makes this batch safe to ship: every
 * bot in every workspace keeps behaving identically until somebody deliberately binds a base to it, and
 * the migration endpoint is the one action that changes which path a bot takes.
 */
class BotKnowledgeReader
{
    /** The morph alias the Bot module registers — the ONLY name Knowledge ever learns for a bot. */
    public const BINDABLE_TYPE = 'bot';

    /**
     * Cap on the composed retrieval query. An embedding call is priced by input, and a query is a
     * QUESTION, not a document: past a couple of thousand characters the extra text stops sharpening the
     * vector and starts averaging it towards the base's centre, which is the opposite of retrieval.
     */
    private const MAX_QUERY_CHARS = 1500;

    /** How many recent comments the query digest draws on, and how much of each. */
    private const QUERY_COMMENTS = 5;

    private const QUERY_COMMENT_CHARS = 200;

    private const QUERY_DESCRIPTION_CHARS = 500;

    public function __construct(
        private KnowledgeBindingService $bindings,
        private KnowledgeRetrievalService $knowledge,
    ) {}

    /**
     * The knowledge this bot should read while working on this task, or NULL when it is bound to no base
     * (or the base it was bound to holds nothing approved).
     *
     * May cost ONE metered embedding call — only in `rag` mode, and only when the budget allows it; every
     * refusal degrades to the free inline compilation inside the Knowledge module rather than surfacing
     * here. See {@see KnowledgeRetrievalService}.
     */
    public function read(Bot $bot, Task $task): ?CompiledKnowledge
    {
        $binding = $this->bindings->get(self::BINDABLE_TYPE, (string) $bot->getKey());

        if ($binding === null) {
            return null;
        }

        return $this->knowledge->forBinding($binding, $this->query($task));
    }

    /**
     * What this bot is trying to do, in the words the task itself uses — the text retrieval matches
     * against.
     *
     * Title first and never truncated: it is the densest statement of the subject and the one line most
     * likely to name the thing the knowledge base has an entry about. Then the description, then the most
     * recent turns of the conversation, because a thread that has moved on ("actually they're asking about
     * refunds") describes the CURRENT need better than a title written three days ago.
     *
     * The FORM contributes its name only. Its schema is a large JSON tree of field definitions, and
     * embedding structure as prose produces a vector that describes "a form" rather than the subject —
     * while the answers themselves are already echoed in the conversation when they matter.
     */
    public function query(Task $task): string
    {
        $task->loadMissing('form');

        $parts = [(string) $task->title];

        if (filled($task->description)) {
            $parts[] = Str::limit((string) $task->description, self::QUERY_DESCRIPTION_CHARS);
        }

        if ($task->form !== null) {
            $parts[] = (string) $task->form->name;
        }

        foreach ($this->recentComments($task) as $comment) {
            $parts[] = $comment;
        }

        $query = trim(implode("\n", array_filter($parts, fn (string $part): bool => trim($part) !== '')));

        return Str::limit($query, self::MAX_QUERY_CHARS, '');
    }

    /**
     * The last few comments, oldest-first, each clipped. Ordered like the context builder's own comment
     * section so the query and the context cannot describe two different conversations.
     *
     * @return array<int, string>
     */
    private function recentComments(Task $task): array
    {
        return $task->comments()
            ->latest('created_at')
            ->limit(self::QUERY_COMMENTS)
            ->get(['id', 'content', 'created_at'])
            ->reverse()
            ->map(function ($comment): string {
                $text = is_string($comment->content)
                    ? $comment->content
                    : (string) json_encode($comment->content, JSON_UNESCAPED_UNICODE);

                return Str::limit($text, self::QUERY_COMMENT_CHARS);
            })
            ->values()
            ->all();
    }
}
