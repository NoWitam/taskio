<?php

namespace App\Modules\Comments\DTOs;

use App\Modules\Bot\Models\Bot;
use Illuminate\Http\Request;

class CommentDTO
{
    public function __construct(
        public readonly string $content,
        public readonly string $authorId,
        public readonly string $authorType = 'user',
    ) {}

    /**
     * HTTP path: a comment is always authored by the current user. Humans cannot
     * post as a bot, so the author type is fixed to 'user' here.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            content: $request->input('content'),
            authorId: auth()->id(),
            authorType: 'user',
        );
    }

    /**
     * Bot-authored comment (used by the bot task-execution flow). Carries the 'bot'
     * morph alias so CommentResource renders the bot as the author.
     */
    public static function fromBot(Bot $bot, string $content): self
    {
        return new self(
            content: $content,
            authorId: $bot->id,
            authorType: 'bot',
        );
    }
}
