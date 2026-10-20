<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $appends = ['is_overdue', 'days_overdue'];

    protected $fillable = [
        'reader_id', 'issued_by', 'issued_at', 'due_at', 'returned_at', 'status',
        'reservation_id', 'payment_id',
        'rental_fee_total', 'rental_fee_received', 'rental_fee_received_at',
        'rental_fee_received_by', 'rental_fee_payment_method', 'rental_fee_receipt_note',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
            'rental_fee_received_at' => 'datetime',
        ];
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reader_id')->withTrashed();
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(LoanReturn::class);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active' && $this->due_at?->isPast();
    }

    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue) {
            return 0;
        }

        return max(1, (int) $this->due_at->copy()->startOfDay()->diffInDays(now()->startOfDay()));
    }
}
