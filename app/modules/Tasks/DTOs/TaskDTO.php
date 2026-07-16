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
        public readonly ?string $assigneeType,
        public readonly ?string $assigneeId,
        public readonly array $labels,
        public readonly array $attachments,
        public readonly ?string $form_id,
        public readonly ?string $approval_pipeline_id,
    ) {}

    public static function fromRequest(Request $request): self
    {
        [$assigneeType, $assigneeId] = self::resolveAssignee($request);

        return new self(
            title: $request->string('title'),
            description: $request->string('description'),
            priority: $request->enum('priority', TaskPriority::class),
            deadline: $request->date('deadline'),
            assigneeType: $assigneeType,
            assigneeId: $assigneeId,
            labels: $request->array('labels'),
            attachments: $request->array('attachments'),
            form_id: $request->string('form_id')->value() ?: null,
            approval_pipeline_id: $request->string('approval_pipeline_id')->value() ?: null,
        );
    }

    /**
     * Resolve the polymorphic assignee from the request.
     *
     * The explicit polymorphic pair (assignee_type + assignee_id) wins when present.
     * Otherwise the legacy `assigned_id` is mapped to a User assignee. An empty value
     * clears the assignee (both null).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolveAssignee(Request $request): array
    {
        $explicitType = $request->string('assignee_type')->value() ?: null;

        if ($explicitType !== null) {
            $explicitId = $request->string('assignee_id')->value() ?: null;

            return [$explicitType, $explicitId];
        }

        $legacyId = $request->string('assigned_id')->value() ?: null;

        return $legacyId !== null ? ['user', $legacyId] : [null, null];
    }
}
