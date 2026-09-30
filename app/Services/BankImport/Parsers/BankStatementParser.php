<?php

namespace App\Services\BankImport\Parsers;

use App\Enums\Bank;
use App\Services\BankImport\ParsedStatement;

interface BankStatementParser
{
    public function bank(): Bank;

    /**
     * @param  list<mixed>  $header
     */
    public function supports(array $header): bool;

    /**
     * @param  list<mixed>  $header
     * @param  list<list<mixed>>  $rows  Rows below the header.
     */
    public function parse(array $header, array $rows): ParsedStatement;
}
