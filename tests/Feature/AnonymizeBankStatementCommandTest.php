<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/varys-anon-'.uniqid();
    mkdir($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

it('anonymizes a csv keeping delimiter, encoding and structure', function (): void {
    $csv = "Intestatario:;Mario Rossi\r\n"
        ."IBAN:;IT60X0542811101000000123456\r\n"
        ."Data;Descrizione;Importo\r\n"
        ."01/09/2026;Bonifico ricevuto da Giulia Bianchi;1.200,00\r\n"
        ."02/09/2026;PAGAMENTO POS CAFFÈ ESSELUNGA;-45,30\r\n";
    $input = $this->dir.'/movimenti.csv';
    file_put_contents($input, mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

    $this->artisan('bank:anonymize', ['file' => $input, '--mask' => ['Rossi']])
        ->doesntExpectOutputToContain('Mario')
        ->doesntExpectOutputToContain('Bianchi')
        ->assertSuccessful();

    $raw = (string) file_get_contents($this->dir.'/movimenti.anon.csv');
    expect(mb_check_encoding($raw, 'UTF-8'))->toBeFalse();

    $output = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $lines = explode("\r\n", trim($output));

    expect($lines)->toHaveCount(5)
        ->and($lines[0])->toBe('Intestatario:;[PREAMBOLO]')
        ->and($lines[1])->toBe('IBAN:;[PREAMBOLO]')
        ->and($lines[2])->toBe('Data;Descrizione;Importo')
        ->and($lines[3])->toContain('Bonifico ricevuto da PERSONA_1')
        ->and($lines[4])->toContain('PAGAMENTO POS CAFFÈ ESSELUNGA')
        ->and($lines[3])->toStartWith('01/09/2026;')
        ->and($output)->not->toContain('Rossi', 'Bianchi', 'IT60X');
});

it('anonymizes an xlsx keeping cell types', function (): void {
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getProperties()->setCreator('Mario Rossi');
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Conto:', '1000/123456'],
        ['Data', 'Operazione', 'Dettagli', 'Importo'],
        [ExcelDate::PHPToExcel(new DateTime('2026-09-01')), 'Bonifico', 'Bonifico disposto a favore di Luca Verdi', -500.0],
    ]);
    $sheet->getStyle('A3')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    $input = $this->dir.'/lista.xlsx';
    (new Xlsx($spreadsheet))->save($input);

    $this->artisan('bank:anonymize', ['file' => $input])->assertSuccessful();

    $result = IOFactory::load($this->dir.'/lista.anon.xlsx');
    $out = $result->getActiveSheet();

    expect($result->getProperties()->getCreator())->toBe('')
        ->and($out->getCell('B1')->getValue())->toBe('[PREAMBOLO]')
        ->and($out->getCell('B2')->getValue())->toBe('Operazione')
        ->and(ExcelDate::isDateTime($out->getCell('A3')))->toBeTrue()
        ->and($out->getCell('A3')->getValue())->toEqual($sheet->getCell('A3')->getValue())
        ->and($out->getCell('C3')->getValue())->toBe('Bonifico disposto a favore di PERSONA_1')
        ->and($out->getCell('D3')->getValue())->toBeFloat()->toBeLessThan(0.0);
});
