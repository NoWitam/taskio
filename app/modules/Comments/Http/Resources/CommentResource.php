<?php

namespace App\Modules\Comments\Http\Resources;

use App\Modules\Bot\Models\Bot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'author' => $this->presentAuthor(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_edited' => $this->created_at->ne($this->updated_at),
        ];
    }

    /**
     * Polymorphic author (User|Bot). Keeps the legacy id/name/email shape so the
     * frontend keeps working; adds `type` + `is_bot` so a bot author can be badged.
     * A bot has no email (null). Null-guarded for a former member whose row vanished.
     *
     * @return array<string, mixed>|null
     */
    private function presentAuthor(): ?array
    {
        $author = $this->author;

        if (!$author) {
            return null;
        }

        $isBot = $author instanceof Bot;

        return [
            'id' => $author->id,
            'name' => $author->name,
            'email' => $isBot ? null : $author->email,
            'type' => $isBot ? 'bot' : 'user',
            'is_bot' => $isBot,
        ];
    }
}
