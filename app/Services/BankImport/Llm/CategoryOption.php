<?php

namespace App\Services\BankImport\Llm;

use App\Enums\CategoryType;

final readonly class CategoryOption
{
    public function __construct(
        public int $id,
        public string $name,
        public CategoryType $type,
    ) {}
}
