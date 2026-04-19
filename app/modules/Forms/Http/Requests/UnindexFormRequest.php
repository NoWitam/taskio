<?php

namespace App\Modules\Forms\Http\Requests;

use App\Modules\Forms\Models\Form;
use Illuminate\Foundation\Http\FormRequest;

class UnindexFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $form = $this->route('form');
        
        if ($form instanceof Form) {
            return $this->user()->can('unindex', $form);
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'backup_indexes' => ['boolean'],
        ];
    }
}
