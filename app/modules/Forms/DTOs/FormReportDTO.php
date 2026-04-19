<?php

namespace App\Modules\Forms\DTOs;

use Illuminate\Http\Request;

class FormReportDTO
{
    public function __construct(
        public readonly string $form_id,
        public readonly string $name,
        public readonly ?string $guidelines,
        public readonly array $sources,
        public readonly string $submissions_from,
        public readonly string $submissions_to
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            form_id: $request->string('form_id'),
            name: $request->string('name'),
            guidelines: $request->string('guidelines', null),
            sources: $request->array('sources'),
            submissions_from: $request->string('submissions_from'),
            submissions_to: $request->string('submissions_to')
        );
    }
}
