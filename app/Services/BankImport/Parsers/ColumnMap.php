<?php

namespace App\Services\BankImport\Parsers;

use Carbon\CarbonImmutable;

/**
 * Reads row cells by header name, since banks place columns at different offsets.
 */
final class ColumnMap
{
    /** @var array<string, int> */
    private array $indexes = [];

    /**
     * @param  list<mixed>  $header
     */
    public function __construct(array $header)
    {
        foreach ($header as $index => $cell) {
            if (is_string($cell) && trim($cell) !== '') {
                $this->indexes[self::normalize($cell)] ??= $index;
            }
        }
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * @param  list<string>  $names
     */
    public function hasAll(array $names): bool
    {
        foreach ($names as $name) {
            if (! isset($this->indexes[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $row
     */
    public function string(array $row, string $name): string
    {
        $value = $row[$this->indexes[$name] ?? -1] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  list<mixed>  $row
     */
    public function date(array $row, string $name): ?CarbonImmutable
    {
        $value = $row[$this->indexes[$name] ?? -1] ?? null;

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($value), $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * @param  list<mixed>  $row
     */
    public function amount(array $row, string $name): ?float
    {
        $value = $row[$this->indexes[$name] ?? -1] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // Italian format "1.234,56" or plain "1234.56".
        $normalized = str_contains($value, ',') ? str_replace(['.', ','], ['', '.'], $value) : $value;
        $normalized = (string) preg_replace('/[^\d.+-]/', '', $normalized);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
