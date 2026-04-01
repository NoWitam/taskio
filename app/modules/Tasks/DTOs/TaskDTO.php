<?php

namespace App\Modules\Tasks\DTOs;

use App\Modules\Tasks\Enums\TaskPriority;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TaskDTO
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly TaskPriority $priority,
        public readonly ?Carbon $deadline,
        public readonly string $assigned,
        public readonly array $labels,
        public readonly array $attachments,
        public readonly ?string $form_id
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            title: $request->string('title'),
            description: $request->string('description'),
            priority: $request->enum('priority', TaskPriority::class),
            deadline: $request->date('deadline'),
            assigned: $request->string('assigned_id'),
            labels: $request->array('labels'),
            attachments: $request->array('attachments'),
            form_id: $request->string('form_id')
        );
    }
}
