<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fine extends Model
{
    protected $fillable = [
        'loan_id', 'loan_return_item_id', 'reader_id', 'overdue_days',
        'quantity', 'daily_rate', 'amount', 'status', 'waived_by',
        'waiver_reason', 'waived_at',
        'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'waived_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reader_id')->withTrashed();
    }

    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(LoanReturnItem::class, 'loan_return_item_id');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by')->withTrashed();
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FinePayment::class);
    }

    public function checkoutPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }
}
