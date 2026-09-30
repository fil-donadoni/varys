<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBankTransactionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'exclude' => ['required', 'boolean'],
            'apply_to_merchant' => ['required', 'boolean'],
        ];
    }
}
