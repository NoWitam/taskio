<?php

namespace App\Modules\Forms\DTOs;

use Illuminate\Http\Request;

class FormSubmissionDTO
{
    public function __construct(
        public readonly string $form_id,
        public readonly string $submittable_type,
        public readonly string $submittable_id,
        public readonly array $data
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            form_id: $request->string('form_id'),
            submittable_type: $request->string('submittable_type'),
            submittable_id: $request->string('submittable_id'),
            data: $request->array('data')
        );
    }
}
