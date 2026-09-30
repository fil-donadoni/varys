<?php

namespace App\Services\BankImport;

use RuntimeException;

class StatementAlreadyImportedException extends RuntimeException
{
    public function __construct(public readonly int $bankImportId)
    {
        parent::__construct('Tutti i movimenti di questo file sono già stati importati.');
    }
}
