<?php

namespace App\Http\Requests\App;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateActualItemRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'between:-9999999999,9999999999', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_numeric($value) && (float) $value === 0.0) {
                    $fail("L'importo non può essere zero.");
                }
            }],
        ];
    }
}
