<?php

namespace App\Services\BankImport;

use App\Enums\Bank;

final readonly class ParsedStatement
{
    /**
     * @param  int  $rowsTotal  Movement rows found in the file, card expenses or not.
     * @param  list<ParsedCardExpense>  $expenses
     */
    public function __construct(
        public Bank $bank,
        public int $rowsTotal,
        public array $expenses,
    ) {}
}
