<?php

namespace Tests\Support;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds bank export files shaped like the real Intesa/ING xlsx exports.
 * Rows come from anonymized samples.
 */
final class BankStatementFixtures
{
    /**
     * @param  list<list<mixed>>  $rows  Values starting with "date:Y-m-d" become Excel date cells.
     */
    public static function xlsx(array $rows, string $startColumn = 'A'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            $column = $startColumn;

            foreach ($row as $value) {
                $coordinate = $column.($rowIndex + 1);

                if (is_string($value) && str_starts_with($value, 'date:')) {
                    $sheet->setCellValue($coordinate, ExcelDate::PHPToExcel(new DateTimeImmutable(substr($value, 5))));
                    $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } elseif ($value !== null) {
                    $sheet->setCellValue($coordinate, $value);
                }

                $column++;
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'bank').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public static function ing(): string
    {
        return self::xlsx([
            ...array_fill(0, 11, []),
            ['DATA CONTABILE', 'DATA VALUTA', 'CAUSALE', 'DESCRIZIONE OPERAZIONE', 'IMPORTO IN EURO'],
            ['date:2026-01-01', 'date:2025-12-30', 'Pagamento Carta', 'Operazione Mastercard del 30/12/2025 alle ore 11:34 con Carta xxxxxxxxxxxx[NOME] Div=EUR Importo in divisa=29.99 / Importo in Euro=29.99 presso AMAZON* ZG3658CU4', -29.99],
            ['date:2026-01-02', 'date:2026-01-02', 'Addebito Diretto', 'Addebito SDD CORE Scad. 02/01/2026 Imp. 780.76 Creditor id. [IBAN] RAPPORTI INTERNI SPORTELLO 00702 Id Mandato 585Q7444512227 Debitore [NOME] [NOME]', -780.76],
            ['date:2026-01-06', 'date:2026-01-04', 'Pagamento Carta', 'Operazione Mastercard del 04/01/2026 alle ore 11:38 con Carta xxxxxxxxxxxx[NOME] Div=EUR Importo in divisa=116.49 / Importo in Euro=116.49 presso IPER MAGENTA NCR - Transazione C-less', -116.49],
            ['date:2026-01-10', 'date:2026-01-10', 'Addebito Carta Di Credito', 'Estratto conto carta di credito al 20260110', -29.9],
            ['date:2026-01-15', 'date:2026-01-15', 'Accredito Bonifico', 'Bonifico istantaneo PERSONA_1 BIC Ordinante BAPPIT22 Anagrafica Ordinante PERSONA_2 Note: rata', 500],
            ['date:2026-02-06', 'date:2026-02-06', 'Bonifico In Uscita', 'BONIFICO DA PERSONA_3 CRO 17513735907 A FAVORE DI Lacos Group srl C. BENEF. [IBAN] NOTE: Fattura 726/2025', -329.4],
            ['date:2026-02-10', 'date:2026-02-08', 'Pagamento Carta', 'Operazione Mastercard del 08/02/2026 alle ore 17:43 con Carta xxxxxxxxxxxx[NOME] Div=EUR Importo in divisa=33.85 / Importo in Euro=33.85 presso WWW.AMAZON.* CI3PN3LQ5', -33.85],
            ['date:2026-03-01', 'date:2026-03-01', 'Interessi E Competenze', 'Competenze liquidazione al 31/12/2025', -0.88],
        ], 'B');
    }

    public static function intesa(): string
    {
        return self::xlsx([
            [],
            ['', 'Conti e Carte:', '[PREAMBOLO]'],
            ['', 'Data inizio periodo:', '01/01/2026'],
            [],
            ['Data', 'Operazione', 'Dettagli', 'Conto o carta', 'Contabilizzazione', 'Categoria ', 'Valuta', 'Importo'],
            ['date:2026-03-31', 'Imposta Di Bollo E/c E Rendiconto', 'Per Supero Giacenza Media', 'Conto [CONTO]', 'CONTABILIZZATO', 'Imposte, bolli e commissioni', 'EUR', -8.4],
            ['date:2026-03-31', 'Farmacia Della Basili. Magenta', 'Effettuato Il 31/03/2026 Alle Ore 0842 Mediante La Carta [CARTA] Presso Farmacia Della Basili. Magenta', 'Conto [CONTO]', 'CONTABILIZZATO', 'Farmacia', 'EUR', -24.21],
            ['date:2026-03-31', 'Canone Carta Di Debito', 'Mese Di Marzo Carta N. [CARTA]', 'Conto [CONTO]', 'CONTABILIZZATO', 'Imposte, bolli e commissioni', 'EUR', -1.5],
            ['date:2026-03-31', 'Bonifico Disposto Da DM GROUP S.R.L.', 'COD.DISP. Bonifico A Vostro Favore', 'Conto [CONTO]', 'CONTABILIZZATO', 'Bonifici ricevuti', 'EUR', 4750],
            ['date:2026-03-30', 'Paypal *aruba Spa', 'Paypal *aruba Spa 05750505', 'SUPERFLASH [CARTA]', 'CONTABILIZZATO', 'TV, Internet, telefono', 'EUR', -12.08],
            ['date:2026-03-24', 'IPER STATION MAGENTA Corso', 'IPER STATION MAGENTA Corso 24/030820 Carta N.[CARTA]ABI 09514 COD.3001781/004545', 'Conto [CONTO]', 'CONTABILIZZATO', 'Generi alimentari e supermercato', 'EUR', -61.67],
            ['date:2026-03-24', 'Antico Vinaio Italia Srl', 'Pagamento Su POS ANTICO VINAIO ITALIA SRL 24/031327 Carta N.[CARTA] COD. 5465441/00001', 'Conto [CONTO]', 'CONTABILIZZATO', 'Ristoranti e bar', 'EUR', -13],
            ['date:2026-03-23', 'Addebito Diretto Disposto A Favore Di TELEPASS SPA', 'Cod. Disp. Nome Telepass Spa', 'Conto [CONTO]', 'CONTABILIZZATO', 'Pedaggi e Telepass', 'EUR', -32.8],
            ['date:2026-03-07', 'Bonifico In Entrata', 'Bonifico In Entrata PERSONA_5', 'SUPERFLASH [CARTA]', 'CONTABILIZZATO', 'Entrate varie', 'EUR', 67],
            ['date:2026-03-05', 'Zalando Payments', 'Zalando Payments Berlin', 'SUPERFLASH [CARTA]', 'CONTABILIZZATO', 'Abbigliamento e accessori', 'EUR', 20.5],
            ['date:2026-03-04', 'Cuore Di Parma', 'Pagamento Su POS CUORE DI PARMA', 'Conto [CONTO]', 'NON CONTABILIZZATO', 'Ristoranti e bar', 'EUR', -15.5],
        ]);
    }
}
