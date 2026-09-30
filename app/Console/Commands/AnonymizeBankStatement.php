<?php

namespace App\Console\Commands;

use App\Services\BankImport\StatementAnonymizer;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Anonymizes a bank statement export (CSV or Excel) locally, masking only personal identifiers.
 * It never prints file contents: only counters, so output can be shared safely.
 */
class AnonymizeBankStatement extends Command
{
    private const int HEADER_SCAN_ROWS = 40;

    protected $signature = 'bank:anonymize
        {file : Percorso del file CSV/XLS/XLSX esportato dalla banca}
        {--output= : Percorso del file anonimizzato (default: <nome>.anon.<ext>)}
        {--mask=* : Testi da mascherare sempre, es. --mask="Mario Rossi" --mask="Rossi"}';

    protected $description = 'Anonimizza un estratto conto (CSV/Excel) mantenendone la struttura';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("File non trovato: {$path}");

            return self::FAILURE;
        }

        /** @var list<string> $masks */
        $masks = array_values(array_filter((array) $this->option('mask'), 'is_string'));
        $anonymizer = new StatementAnonymizer($masks);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $output = $this->option('output') ?: $this->defaultOutputPath($path, $extension);

        $summary = in_array($extension, ['csv', 'txt'], true)
            ? $this->anonymizeCsv($path, (string) $output, $anonymizer)
            : $this->anonymizeSpreadsheet($path, (string) $output, $anonymizer);

        $this->info("File anonimizzato: {$output}");
        $this->table(['Voce', 'Valore'], [
            ...array_map(fn (string $key, int|string $value): array => [$key, $value], array_keys($summary), $summary),
            ...array_map(fn (string $key, int $value): array => [$key, $value], array_keys($anonymizer->stats()), $anonymizer->stats()),
        ]);
        $this->warn('Controlla il file a mano prima di condividerlo: nomi in testo libero potrebbero non essere stati riconosciuti.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int|string>
     */
    private function anonymizeCsv(string $path, string $output, StatementAnonymizer $anonymizer): array
    {
        $raw = (string) file_get_contents($path);
        $bom = str_starts_with($raw, "\xEF\xBB\xBF") ? "\xEF\xBB\xBF" : '';
        $raw = substr($raw, strlen($bom));

        $encoding = mb_check_encoding($raw, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        $content = $encoding === 'UTF-8' ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $delimiter = $this->detectDelimiter($content);

        $rows = $this->readCsvRows($content, $delimiter);
        $headerIndex = $this->findHeaderRow($rows, $anonymizer);
        $personColumns = $this->personColumns($rows[$headerIndex] ?? [], $anonymizer);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $column => $value) {
                $rows[$rowIndex][$column] = match (true) {
                    $headerIndex !== null && $rowIndex < $headerIndex => $anonymizer->anonymizePreambleCell($value),
                    $rowIndex === $headerIndex => $anonymizer->anonymizeHeaderCell($value),
                    default => $anonymizer->anonymizeValue($value, in_array($column, $personColumns, true)),
                };
            }
        }

        $stream = fopen('php://temp', 'r+');
        assert($stream !== false);

        foreach ($rows as $row) {
            fputcsv($stream, $row, $delimiter, '"', '', $eol);
        }

        rewind($stream);
        $result = (string) stream_get_contents($stream);
        fclose($stream);

        if ($encoding !== 'UTF-8') {
            $result = (string) mb_convert_encoding($result, 'Windows-1252', 'UTF-8');
        }

        file_put_contents($output, $bom.$result);

        return [
            'formato' => 'CSV',
            'encoding' => $encoding.($bom !== '' ? ' (BOM)' : ''),
            'separatore' => $delimiter === "\t" ? 'TAB' : $delimiter,
            'righe' => count($rows),
            'riga_intestazione' => $headerIndex === null ? 'non trovata' : $headerIndex + 1,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function anonymizeSpreadsheet(string $path, string $output, StatementAnonymizer $anonymizer): array
    {
        $type = IOFactory::identify($path);
        $spreadsheet = IOFactory::load($path);

        $this->clearMetadata($spreadsheet);

        $rowsCount = 0;
        $headers = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheetIndex => $sheet) {
            $this->clearSheetMetadata($sheet, $sheetIndex);

            $values = $sheet->toArray(null, true, false, false);
            /** @var list<list<string>> $stringRows */
            $stringRows = array_map(fn (array $row): array => array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values($row)), array_values($values));
            $headerIndex = $this->findHeaderRow($stringRows, $anonymizer);
            $personColumns = $this->personColumns($stringRows[$headerIndex] ?? [], $anonymizer);
            $headers[] = $headerIndex === null ? 'non trovata' : (string) ($headerIndex + 1);

            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex = $row->getRowIndex() - 1;
                $rowsCount++;

                $cells = $row->getCellIterator();
                $cells->setIterateOnlyExistingCells(true);

                foreach ($cells as $cell) {
                    $column = $this->columnIndex($cell);
                    $this->anonymizeCell($cell, $anonymizer, match (true) {
                        $headerIndex !== null && $rowIndex < $headerIndex => 'preamble',
                        $rowIndex === $headerIndex => 'header',
                        in_array($column, $personColumns, true) => 'person',
                        default => 'data',
                    });
                }
            }
        }

        IOFactory::createWriter($spreadsheet, $type)->save($output);

        return [
            'formato' => $type,
            'fogli' => $spreadsheet->getSheetCount(),
            'righe' => $rowsCount,
            'riga_intestazione' => implode(', ', $headers),
        ];
    }

    private function anonymizeCell(Cell $cell, StatementAnonymizer $anonymizer, string $role): void
    {
        $dataType = $cell->getDataType();
        $value = $dataType === DataType::TYPE_FORMULA ? $cell->getOldCalculatedValue() : $cell->getValue();

        if ($value === null || $value === '') {
            return;
        }

        if ($role === 'preamble') {
            if (ExcelDate::isDateTime($cell)) {
                return;
            }

            $cell->setValueExplicit($anonymizer->anonymizePreambleCell(is_scalar($value) ? (string) $value : ''), DataType::TYPE_STRING);

            return;
        }

        // Dates and amounts are kept as they are.
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return;
        }

        $string = is_scalar($value) ? (string) $value : '';

        $cell->setValueExplicit(
            $role === 'header'
                ? $anonymizer->anonymizeHeaderCell($string)
                : $anonymizer->anonymizeValue($string, $role === 'person'),
            DataType::TYPE_STRING,
        );
    }

    private function clearMetadata(Spreadsheet $spreadsheet): void
    {
        $spreadsheet->getProperties()
            ->setCreator('')
            ->setLastModifiedBy('')
            ->setTitle('')
            ->setSubject('')
            ->setDescription('')
            ->setKeywords('')
            ->setCategory('')
            ->setCompany('')
            ->setManager('');
    }

    private function clearSheetMetadata(Worksheet $sheet, int $sheetIndex): void
    {
        if (preg_match('/\d{4,}/', $sheet->getTitle()) === 1) {
            $sheet->setTitle('Foglio'.($sheetIndex + 1));
        }

        $sheet->setComments([]);

        $sheet->getHeaderFooter()
            ->setOddHeader('')
            ->setOddFooter('')
            ->setEvenHeader('')
            ->setEvenFooter('')
            ->setFirstHeader('')
            ->setFirstFooter('');
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function findHeaderRow(array $rows, StatementAnonymizer $anonymizer): ?int
    {
        foreach (array_slice($rows, 0, self::HEADER_SCAN_ROWS) as $index => $row) {
            if ($anonymizer->isHeaderRow($row)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $header
     * @return list<int>
     */
    private function personColumns(array $header, StatementAnonymizer $anonymizer): array
    {
        return array_keys(array_filter($header, fn (string $cell): bool => $anonymizer->isPersonColumn($cell)));
    }

    private function columnIndex(Cell $cell): int
    {
        return Coordinate::columnIndexFromString($cell->getColumn()) - 1;
    }

    private function detectDelimiter(string $content): string
    {
        $sample = implode("\n", array_slice(explode("\n", $content), 0, 50));
        $counts = [];

        foreach ([';', ',', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @return list<list<string>>
     */
    private function readCsvRows(string $content, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        assert($stream !== false);
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn (?string $value): string => $value ?? '', $row);
        }

        fclose($stream);

        return $rows;
    }

    private function defaultOutputPath(string $path, string $extension): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return "{$directory}/{$name}.anon.{$extension}";
    }
}
