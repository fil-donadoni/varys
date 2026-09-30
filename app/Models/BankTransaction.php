<?php

namespace App\Models;

use App\Enums\CategorizationSource;
use App\Enums\TransactionStatus;
use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $operation_date
 * @property Carbon|null $booking_date
 * @property CategorizationSource|null $categorization_source
 * @property TransactionStatus $status
 */
class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation_date' => 'date',
            'booking_date' => 'date',
            'amount' => 'decimal:2',
            'confidence' => 'decimal:2',
            'categorization_source' => CategorizationSource::class,
            'status' => TransactionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BankImport, $this>
     */
    public function bankImport(): BelongsTo
    {
        return $this->belongsTo(BankImport::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
