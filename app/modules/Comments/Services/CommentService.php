<?php

namespace App\Modules\Comments\Services;

use App\Modules\Bot\Services\BotTaskExecutionService;
use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;

class CommentService
{
    public function create(Model $commentable, CommentDTO $dto): Comment
    {
        $comment = $commentable->comments()->create([
            'content' => $dto->content,
            'author_type' => $dto->authorType,
            'author_id' => $dto->authorId,
        ]);

        // Resume a waiting bot run when a HUMAN replies on a task. The author-type gate
        // is the loop guard: a bot's own comment (author_type='bot') never resumes.
        if ($dto->authorType === 'user' && $commentable instanceof Task) {
            app(BotTaskExecutionService::class)->resumeFromHumanReply($commentable);
        }

        return $comment;
    }

    public function update(Comment $comment, CommentDTO $dto): Comment
    {
        $comment->update([
            'content' => $dto->content,
        ]);

        return $comment->fresh();
    }

    public function delete(Comment $comment): void
    {
        $comment->delete();
    }

    public function getComments(Model $commentable)
    {
        return $commentable->comments()
            // A comment's author is part of its permanent record: keep it resolvable
            // even if the author has since left the workspace. The member-scope bypass
            // lives inside Comment::author() (User branch), so a plain ->with('author')
            // works for both User and Bot authors.
            ->with('author')
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);
    }
}
