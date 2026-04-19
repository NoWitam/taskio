<?php

namespace App\Modules\Comments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comment = $this->route('comment');
        return $this->user()->can('delete', $comment);
    }

    public function rules(): array
    {
        return [];
    }
}
