<?php

namespace App\Models;

use App\Enums\MerchantMatchType;
use Database\Factories\MerchantRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property MerchantMatchType $match_type
 */
class MerchantRule extends Model
{
    /** @use HasFactory<MerchantRuleFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => MerchantMatchType::class,
            'always_ask' => 'boolean',
            'times_confirmed' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
