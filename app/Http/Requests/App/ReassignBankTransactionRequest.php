<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReassignBankTransactionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', Rule::requiredIf(fn (): bool => ! $this->boolean('exclude')), 'integer', 'exists:categories,id'],
            'exclude' => ['required', 'boolean'],
        ];
    }
}
