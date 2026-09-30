<?php

namespace App\Http\Requests\App;

use App\Enums\Bank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankImportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,xls', 'max:10240'],
            'bank' => ['nullable', Rule::enum(Bank::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Seleziona un file da importare.',
            'file.extensions' => 'Sono supportati solo file Excel (.xlsx, .xls).',
        ];
    }
}
