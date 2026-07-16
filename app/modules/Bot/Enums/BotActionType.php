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
}
