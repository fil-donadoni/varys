<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpsertActualRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'entries' => ['required', 'array'],
            'entries.*.category_id' => ['required', 'exists:categories,id'],
            'entries.*.amount' => ['required', 'numeric', 'min:0'],
            'entries.*.description' => ['nullable', 'string', 'max:255'],
            'entries.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
