<?php

namespace App\Modules\Approvals\DTOs;

class ApprovalQueueItem
{
    public function __construct(
        public readonly string $type_label,
        public readonly string $type_icon,
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $extra_fields,
        public readonly ?array $form,
        public readonly ?string $comments_url,
    ) {}
}
