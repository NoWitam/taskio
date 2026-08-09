<?php

namespace App\Modules\Bot\Enums;

/**
 * Catalogue of actions a bot can perform while participating in a task.
 *
 * B4 makes the bot an INTERACTIVE participant, so a task may see MANY runs
 * (initial + resumes after a human reply + revisions after a reject). Each run
 * records a `task_started` (with a `run` discriminator in the payload); the
 * interactive lifecycle adds question_asked / resumed / revision_started / handed_over.
 */
enum BotActionType: string
{
    case TaskStarted = 'task_started';
    case FormFilled = 'form_filled';
    case Commented = 'commented';
    case SubmittedToTest = 'submitted_to_test';
    case MarkedDone = 'marked_done';
    case ExecutionFailed = 'execution_failed';

    // B4 interactive lifecycle.
    case QuestionAsked = 'question_asked';
    case Resumed = 'resumed';
    case RevisionStarted = 'revision_started';
    case HandedOver = 'handed_over';

    // B5: a registry tool (fetch_url / web_search / generate_file / read_attachments)
    // was invoked. Payload carries small, non-sensitive meta only.
    case ToolUsed = 'tool_used';

    /**
     * B6: the bot READ its bound knowledge base while assembling this run's context.
     *
     * Recorded because a bot reads its base LIVE: the text it saw is whatever the base said at that
     * instant, and by the time anyone asks "why did it say that" the base has moved on. The payload
     * therefore names the entries AND the revision each of them was at — the receipt
     * ({@see \App\Modules\Knowledge\DTOs\CompiledKnowledge::auditPayload()}) — plus which mode ran and
     * what the budget left out. No entry TEXT is stored: the revisions already hold it, and copying a
     * base's contents into an audit row per run would duplicate the knowledge base into the log.
     */
    case KnowledgeRead = 'knowledge_read';
}
