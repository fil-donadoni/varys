<?php

namespace App\Services\BankImport;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads the first sheet of a spreadsheet into plain rows.
 * Excel date cells become CarbonImmutable, everything else keeps its raw value.
 */
class SpreadsheetReader
{
    /**
     * @return list<list<mixed>>
     */
    public function read(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $rows = [];

        foreach ($sheet->toArray(null, true, false, true) as $rowNumber => $cells) {
            $row = [];

            foreach ($cells as $column => $value) {
                if ((is_int($value) || is_float($value)) && ExcelDate::isDateTime($sheet->getCell($column.$rowNumber))) {
                    $value = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))->startOfDay();
                }

                $row[] = is_string($value) ? trim($value) : $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
