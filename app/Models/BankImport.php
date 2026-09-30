<?php

namespace App\Models;

use App\Enums\Bank;
use App\Enums\BankImportStatus;
use Database\Factories\BankImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Bank $bank
 * @property BankImportStatus $status
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $completed_at
 * @property array<string, int>|null $anonymization_stats
 * @property array<string, int|string>|null $llm_stats
 * @property Carbon|null $llm_ran_at
 */
class BankImport extends Model
{
    /** @use HasFactory<BankImportFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bank' => Bank::class,
            'status' => BankImportStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_duplicates' => 'integer',
            'completed_at' => 'datetime',
            'anonymization_stats' => 'array',
            'llm_stats' => 'array',
            'llm_ran_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
