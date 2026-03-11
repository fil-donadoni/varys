<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'declared_balance' => 'decimal:2',
            'calculated_balance' => 'decimal:2',
            'adjustment' => 'decimal:2',
        ];
    }
}
