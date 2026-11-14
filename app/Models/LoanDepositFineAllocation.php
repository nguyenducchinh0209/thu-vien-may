<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanDepositFineAllocation extends Model
{
    protected $fillable = ['settlement_id', 'fine_id', 'deposit_amount', 'additional_amount'];

    protected function casts(): array
    {
        return ['deposit_amount' => 'decimal:2', 'additional_amount' => 'decimal:2'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(LoanDepositSettlement::class, 'settlement_id');
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }
}
