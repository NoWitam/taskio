<?php

namespace App\Modules\Comments\DTOs;

use Illuminate\Http\Request;

class CommentDTO
{
    public function __construct(
        public readonly string $content,
        public readonly string $authorId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            content: $request->input('content'),
            authorId: auth()->id(),
        );
    }
}
