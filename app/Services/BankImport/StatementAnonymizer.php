<?php

namespace App\Services\BankImport;

/**
 * Masks personal identifiers in bank statements so they can be used as parser fixtures.
 * Dates, amounts and descriptions are kept untouched: the parser needs them as they are.
 */
final class StatementAnonymizer
{
    public const string PREAMBLE_PLACEHOLDER = '[PREAMBOLO]';

    private const array HEADER_KEYWORDS = [
        'data', 'valuta', 'contabile', 'operazione', 'descrizione', 'causale', 'importo',
        'dare', 'avere', 'entrate', 'uscite', 'addebiti', 'accrediti', 'saldo', 'divisa',
        'categoria', 'dettagli', 'stato', 'movimento', 'movimenti', 'tipologia', 'conto',
    ];

    private const array PERSON_HEADER_KEYWORDS = [
        'ordinante', 'beneficiario', 'controparte', 'nominativo', 'intestatario', 'mittente', 'destinatario',
    ];

    private const string PERSON_KEYWORDS_PATTERN = '/(?<![\p{L}])(?:bonifico|giroconto|ordinante|beneficiario|mittente|destinatario|a\s+favore\s+di|ord\.?|ben\.?|mitt\.?|dest\.?)(?=[\s:]|$)/iu';

    private const array NAME_FILLER_WORDS = [
        'da', 'a', 'di', 'verso', 'favore', 'vostro', 'suo', 'nostro', 'ricevuto', 'disposto', 'eseguito',
        'sepa', 'sct', 'istantaneo', 'inst', 'in', 'entrata', 'uscita', 'per', 'conto', 'online', 'web', 'app',
    ];

    private const array NAME_STOP_WORDS = [
        'causale', 'cro', 'trn', 'rif', 'data', 'id', 'note', 'commissioni', 'info', 'addebito', 'accredito',
        'bonifico', 'ordinante', 'beneficiario', 'iban', 'bic', 'swift', 'valuta', 'importo', 'eur', 'euro',
    ];

    private const string COMPANY_PATTERN = '/\b(?:s\.?p\.?a|s\.?r\.?l|s\.?n\.?c|s\.?a\.?s|s\.?c\.?a\.?r\.?l|spa|srl|snc|sas|ltd|gmbh|inc|bv|sa)\b\.?/iu';

    /** @var array<string, string> */
    private array $people = [];

    /** @var array<string, int> */
    private array $stats = [
        'people' => 0,
        'ibans' => 0,
        'cards' => 0,
        'fiscal_codes' => 0,
        'accounts' => 0,
        'emails' => 0,
        'custom_masks' => 0,
        'preamble_cells' => 0,
    ];

    /**
     * @param  list<string>  $customMasks  Extra strings (e.g. the account holder name) to always mask.
     */
    public function __construct(
        private readonly array $customMasks = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $cells
     */
    public function isHeaderRow(array $cells): bool
    {
        $matches = 0;

        foreach ($cells as $cell) {
            if (! is_string($cell) || trim($cell) === '') {
                continue;
            }

            $words = preg_split('/[^\p{L}]+/u', mb_strtolower($cell), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (array_intersect($words, self::HEADER_KEYWORDS) !== []) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    public function isPersonColumn(string $header): bool
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($header), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($words, self::PERSON_HEADER_KEYWORDS) !== [];
    }

    /**
     * Preamble cells (above the header row) are masked unless they are pure labels like "Periodo:".
     */
    public function anonymizePreambleCell(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/^[\p{L}\s.\'()\/-]{1,40}:$/u', $trimmed) === 1) {
            return $value;
        }

        $this->stats['preamble_cells']++;

        return self::PREAMBLE_PLACEHOLDER;
    }

    public function anonymizeHeaderCell(string $value): string
    {
        return $this->applyCustomMasks($value);
    }

    public function anonymizeValue(string $value, bool $isPersonColumn = false): string
    {
        if (trim($value) === '') {
            return $value;
        }

        if ($isPersonColumn) {
            return $this->maskPerson($value);
        }

        return $this->scrubText($value);
    }

    public function scrubText(string $value): string
    {
        $value = $this->applyCustomMasks($value);

        $value = $this->replaceCounting('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[EMAIL]', $value, 'emails');
        $value = $this->replaceCounting('/\b[A-Z]{2}\d{2}(?:[A-Z0-9]{11,30}|(?: [A-Z0-9]{4}){2,7}(?: [A-Z0-9]{1,3})?)\b/', '[IBAN]', $value, 'ibans');
        // Fiscal codes can be embedded in longer codes (e.g. SDD mandate ids): no word boundaries.
        $value = $this->replaceCounting('/[A-Z]{6}\d{2}[ABCDEHLMPRST]\d{2}[A-Z]\d{3}[A-Z]/i', '[CF]', $value, 'fiscal_codes');
        $value = $this->replaceCounting('/(?<!\d)\d{4}([\s-]?)\d{4}\1\d{4}\1\d{4}(?:\d{3})?(?!\d)/', '[CARTA]', $value, 'cards');
        $value = $this->maskPartialCardNumbers($value);
        $value = $this->replaceCounting('/\b(conto\s+(?:n\.?\s*)?)\d{3,5}\/[\d\[\]A-Z]{4,}/iu', '$1[CONTO]', $value, 'accounts');

        return $this->maskPeopleAfterKeywords($value);
    }

    public function maskPerson(string $name): string
    {
        $name = trim($name);

        if ($name === '' || preg_match(self::COMPANY_PATTERN, $name) === 1) {
            return $name;
        }

        $key = mb_strtoupper((string) preg_replace('/\s+/u', ' ', $name));

        if (! isset($this->people[$key])) {
            $this->people[$key] = 'PERSONA_'.(count($this->people) + 1);
        }

        $this->stats['people']++;

        return $this->people[$key];
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    private function applyCustomMasks(string $value): string
    {
        foreach ($this->customMasks as $mask) {
            if (trim($mask) === '') {
                continue;
            }

            $value = $this->replaceCounting('/'.preg_quote($mask, '/').'/iu', '[NOME]', $value, 'custom_masks');
        }

        return $value;
    }

    /**
     * Masks bank-masked card numbers that still expose some digits,
     * e.g. "5167 XXXX XXXX XX60", "5167xxxxxxxxxx60", "****534207000006".
     */
    private function maskPartialCardNumbers(string $value): string
    {
        return (string) preg_replace_callback(
            '/(?<![\dxX*])[\dxX*](?:[\s-]?[\dxX*]){11,18}(?!\d)/',
            function (array $m): string {
                if (preg_match('/[xX*]/', $m[0]) !== 1 || preg_match('/\d/', $m[0]) !== 1) {
                    return $m[0];
                }

                $this->stats['cards']++;

                return '[CARTA]';
            },
            $value,
        );
    }

    private function replaceCounting(string $pattern, string $replacement, string $value, string $stat): string
    {
        $count = 0;
        $result = preg_replace($pattern, $replacement, $value, -1, $count);
        $this->stats[$stat] += $count;

        return $result ?? $value;
    }

    /**
     * Masks names following keywords like "Bonifico ... da", "Ordinante:", "Beneficiario".
     */
    private function maskPeopleAfterKeywords(string $value): string
    {
        if (preg_match_all(self::PERSON_KEYWORDS_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $value;
        }

        $boundaries = array_map(fn (array $match): array => [$match[1], $match[1] + strlen($match[0])], $matches[0]);
        $result = substr($value, 0, $boundaries[0][1]);

        foreach ($boundaries as $i => [, $segmentStart]) {
            $nextKeywordStart = $boundaries[$i + 1][0] ?? strlen($value);
            $nextSegmentStart = $boundaries[$i + 1][1] ?? strlen($value);

            $result .= $this->maskNameInSegment(substr($value, $segmentStart, $nextKeywordStart - $segmentStart))
                .substr($value, $nextKeywordStart, $nextSegmentStart - $nextKeywordStart);
        }

        return $result;
    }

    private function maskNameInSegment(string $segment): string
    {
        $tokens = preg_split('/(\s+|:)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $nameStart = null;
        $nameEnd = null;
        $nameWords = 0;

        foreach ($tokens as $index => $token) {
            if (trim($token) === '' || $token === ':') {
                continue;
            }

            $lower = mb_strtolower(rtrim($token, '.,;'));

            if ($nameStart === null && in_array($lower, self::NAME_FILLER_WORDS, true)) {
                continue;
            }

            // "[NOME]" (a custom mask) is part of a name: keep masking the words around it.
            $isNameWord = (preg_match('/^\p{L}[\p{L}\'.&-]*[,;]?$/u', $token) === 1 || $token === '[NOME]')
                && ! in_array($lower, self::NAME_STOP_WORDS, true);

            if (! $isNameWord || $nameWords >= 4) {
                break;
            }

            $nameStart ??= $index;
            $nameEnd = $index;
            $nameWords++;
        }

        if ($nameStart === null || $nameEnd === null) {
            return $segment;
        }

        $name = implode('', array_slice($tokens, $nameStart, $nameEnd - $nameStart + 1));
        $trailing = preg_match('/[,;]$/', $name) === 1 ? substr($name, -1) : '';
        $masked = $this->maskPerson(rtrim($name, ',;'));

        return implode('', array_slice($tokens, 0, $nameStart)).$masked.$trailing.implode('', array_slice($tokens, $nameEnd + 1));
    }
}
