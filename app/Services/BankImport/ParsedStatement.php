<?php

namespace App\Services\BankImport;

use App\Enums\Bank;

final readonly class ParsedStatement
{
    /**
     * @param  int  $rowsTotal  Movement rows found in the file, including skipped ones.
     * @param  list<ParsedTransaction>  $transactions
     */
    public function __construct(
        public Bank $bank,
        public int $rowsTotal,
        public array $transactions,
    ) {}
}
