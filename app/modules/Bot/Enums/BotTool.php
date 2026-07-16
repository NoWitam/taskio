<?php

namespace App\Modules\Bot\Enums;

/**
 * Registry ids for the OPTIONAL task-execution tools a bot can be granted (B5). These
 * are separate from the four ALWAYS-present interaction tools (post_comment, fill_form,
 * ask_and_wait, finish). A bot exposes a registry tool only when its
 * task_execution.tools[] includes the id AND the tool is available (see BotToolRegistry).
 *
 * This enum is the single source of truth for the ids — request validation, the
 * registry, and the discovery endpoint all read from it.
 */
enum BotTool: string
{
    case FetchUrl = 'fetch_url';
    case WebSearch = 'web_search';
    case GenerateFile = 'generate_file';
    case ReadAttachments = 'read_attachments';

    /** @return array<int, string> the string ids, for `in:` validation. */
    public static function ids(): array
    {
        return array_map(fn (self $tool) => $tool->value, self::cases());
    }
}
