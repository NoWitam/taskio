<?php

namespace App\Modules\Approvals\Tools;

use App\Modules\Approvals\Interfaces\Approvable;
use App\Modules\Approvals\Models\ApprovalStage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetEntityDetails implements Tool
{
    public function __construct(
        private Model&Approvable $entity,
        private ApprovalStage $stage,
    ) {}

    public function description(): Stringable|string
    {
        return "Pobiera szczegóły encji oczekującej na zatwierdzenie: typ, nazwę, opis, dodatkowe pola, "
            . "dane formularza i jego wypełnienie. Wywołaj to narzędzie jako pierwsze aby zapoznać się z elementem.";
    }

    public function handle(Request $request): Stringable|string
    {
        $queueItem = $this->entity->toApprovalQueueItem();

        $data = [
            'entity' => [
                'type' => $queueItem->type_label,
                'name' => $queueItem->name,
                'description' => $queueItem->description,
                'extra_fields' => $queueItem->extra_fields,
            ],
            'form' => $queueItem->form,
            'stage' => [
                'name' => $this->stage->name,
                'criteria' => $this->stage->description,
            ],
        ];

        if ($request['include_comments_count'] ?? false) {
            $data['comments_count'] = $this->entity->comments()->count();
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_comments_count' => $schema->boolean()
                ->description('Czy dołączyć liczbę komentarzy do odpowiedzi.')
                ->nullable()
                ->required(),
        ];
    }
}
