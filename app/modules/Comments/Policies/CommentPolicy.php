<?php

namespace App\Modules\Comments\Policies;

use App\Models\User;
use App\Modules\Comments\Models\Comment;

class CommentPolicy
{
    /**
     * Determine whether the user can update the comment.
     */
    public function update(?User $user, Comment $comment): bool
    {
        if ($user === null) {
            return false;
        }

        // Tylko autor komentarza może go edytować
        return $comment->author_id === $user->id;
    }

    /**
     * Determine whether the user can delete the comment.
     */
    public function delete(?User $user, Comment $comment): bool
    {
        if ($user === null) {
            return false;
        }

        // Autor komentarza lub creator obiektu może usunąć komentarz
        $isAuthor = $comment->author_id === $user->id;
        $isOwner = $comment->commentable && 
                   property_exists($comment->commentable, 'creator_id') && 
                   $comment->commentable->creator_id === $user->id;

        return $isAuthor || $isOwner;
    }
}
