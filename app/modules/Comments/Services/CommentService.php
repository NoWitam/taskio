<?php

namespace App\Modules\Comments\Services;

use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Models\Comment;
use Illuminate\Database\Eloquent\Model;

class CommentService
{
    public function create(Model $commentable, CommentDTO $dto): Comment
    {
        return $commentable->comments()->create([
            'content' => $dto->content,
            'author_id' => $dto->authorId,
        ]);
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
            // even if the author has since left the workspace. WorkspaceMemberScope on
            // User would otherwise null it out (and the resource would 500).
            ->with(['author' => fn ($query) => $query->withoutWorkspaceMemberScope()])
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);
    }
}
