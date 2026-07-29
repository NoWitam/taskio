<?php

namespace App\Modules\Generator\Http\Requests;

/**
 * Update shares the Store validation rules (identity + content_type + slots + per-part content); only the
 * authorization target differs (an existing template resolved from the route, gated by
 * TemplatePolicy::update — creator-only).
 */
class UpdateTemplateRequest extends StoreTemplateRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('template'));
    }
}
