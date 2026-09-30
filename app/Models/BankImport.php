<?php

namespace App\Models;

use App\Enums\Bank;
use App\Enums\BankImportStatus;
use Database\Factories\BankImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'rows_card_expenses' => 'integer',
            'rows_duplicates' => 'integer',
            'completed_at' => 'datetime',
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
