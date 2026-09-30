<?php

namespace App\Services\BankImport;

use App\Enums\Bank;
use App\Services\BankImport\Parsers\BankStatementParser;
use App\Services\BankImport\Parsers\IngParser;
use App\Services\BankImport\Parsers\IntesaParser;

/**
 * Finds the header row, picks the bank parser and extracts card expenses.
 */
class StatementParser
{
    private const int HEADER_SCAN_ROWS = 40;

    /** @var list<BankStatementParser> */
    private array $parsers;

    public function __construct(private readonly SpreadsheetReader $reader)
    {
        $this->parsers = [new IntesaParser, new IngParser];
    }

    public function parseFile(string $path, ?Bank $bank = null): ParsedStatement
    {
        return $this->parseRows($this->reader->read($path), $bank);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    public function parseRows(array $rows, ?Bank $bank = null): ParsedStatement
    {
        foreach (array_slice($rows, 0, self::HEADER_SCAN_ROWS) as $index => $header) {
            foreach ($this->parsers as $parser) {
                if (($bank === null || $parser->bank() === $bank) && $parser->supports($header)) {
                    return $parser->parse($header, array_slice($rows, $index + 1));
                }
            }
        }

        throw new UnsupportedStatementException($bank === null
            ? 'Formato del file non riconosciuto: sono supportati gli export Excel di Intesa Sanpaolo e ING.'
            : "Il file non sembra un export di {$bank->label()}.");
    }
}
