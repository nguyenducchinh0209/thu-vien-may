<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanDepositSettlement extends Model
{
    protected $fillable = [
        'loan_deposit_id', 'deposit_used', 'refund_amount', 'refund_method',
        'additional_received', 'additional_method', 'receipt_note', 'settled_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'deposit_used' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'additional_received' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(LoanDeposit::class, 'loan_deposit_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by')->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LoanDepositFineAllocation::class, 'settlement_id');
    }
}
