<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class SyncBudgetEntryItemsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'category_id' => ['required', 'exists:categories,id'],
            'items' => ['present', 'array'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.is_invoiced' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items.*.description' => 'causale',
            'items.*.amount' => 'importo',
        ];
    }
}
