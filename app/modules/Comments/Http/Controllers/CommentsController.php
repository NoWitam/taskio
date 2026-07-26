<?php

namespace App\Modules\Comments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Http\Requests\DeleteCommentRequest;
use App\Modules\Comments\Http\Requests\StoreCommentRequest;
use App\Modules\Comments\Http\Requests\UpdateCommentRequest;
use App\Modules\Comments\Http\Resources\CommentResource;
use App\Modules\Comments\Models\Comment;
use App\Modules\Comments\Services\CommentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommentsController extends Controller
{
    public function __construct(
        private CommentService $service
    ) {}

    public function index(string $module, string $id): AnonymousResourceCollection
    {
        $commentable = $this->resolveCommentable($module, $id);

        $comments = $this->service->getComments($commentable);

        return CommentResource::collection($comments);
    }

    public function store(StoreCommentRequest $request, string $module, string $id): CommentResource
    {
        $commentable = $this->resolveCommentable($module, $id);

        $comment = $this->service->create(
            $commentable,
            CommentDTO::fromRequest($request)
        );

        return CommentResource::make(
            $comment->load('author')
        );
    }

    public function update(UpdateCommentRequest $request, Comment $comment): CommentResource
    {
        $comment = $this->service->update(
            $comment,
            CommentDTO::fromRequest($request)
        );

        return CommentResource::make(
            $comment->load('author')
        );
    }

    public function destroy(DeleteCommentRequest $request, Comment $comment): \Illuminate\Http\JsonResponse
    {
        $this->service->delete($comment);

        return response()->json([
            'message' => 'Comment deleted successfully',
        ]);
    }

    private function resolveCommentable(string $module, string $id): Model
    {
        $modelClass = match ($module) {
            'tasks' => \App\Modules\Tasks\Models\Task::class,
            // Disk items use their SINGULAR morph aliases — the same {module} convention the
            // generic changelog endpoint rides (/file/{id}/changelog). All TenantAware, so
            // findOrFail is workspace-scoped exactly like tasks.
            'file' => \App\Modules\Disk\Models\File::class,
            'folder' => \App\Modules\Disk\Models\Folder::class,
            // Dodaj tutaj inne moduły w przyszłości
            default => throw new \Exception("Module {$module} not supported for comments")
        };

        return $modelClass::findOrFail($id);
    }
}
