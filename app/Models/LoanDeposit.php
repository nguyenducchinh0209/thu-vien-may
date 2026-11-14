<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanDeposit extends Model
{
    protected $fillable = ['loan_id', 'amount', 'method', 'receipt_note', 'received_by', 'received_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(LoanDepositSettlement::class);
    }
}
