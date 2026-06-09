<?php

namespace App\Modules\Approvals\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetEntityComments implements Tool
{
    public function __construct(
        private Model $entity,
    ) {}

    public function description(): Stringable|string
    {
        return "Pobiera komentarze powiązane z encją do zatwierdzenia. "
            . "Użyj aby zapoznać się z dyskusją i historią ustaleń na temat elementu. "
            . "Przekaż 'cursor' z poprzedniej odpowiedzi aby załadować kolejną porcję komentarzy. "
            . "Pierwsze wywołanie — bez kursora.";
    }

    public function handle(Request $request): Stringable|string
    {
        $cursor = $request['cursor'] ?? null;

        $query = $this->entity->comments()
            ->with('author:id,name,email')
            ->orderBy('created_at', 'asc');

        $paginator = $query->cursorPaginate(perPage: 20, cursor: $cursor);

        $comments = collect($paginator->items())->map(fn ($comment) => [
            'author' => $comment->author?->name ?? 'Nieznany',
            'content' => $comment->content,
            'created_at' => $comment->created_at->format('Y-m-d H:i'),
        ])->all();

        return json_encode([
            'comments' => $comments,
            'has_more' => $paginator->hasMorePages(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()
                ->description('Kursor do pobrania kolejnej porcji komentarzy. Pomiń przy pierwszym wywołaniu.')
                ->nullable()
                ->required(),
        ];
    }
}
