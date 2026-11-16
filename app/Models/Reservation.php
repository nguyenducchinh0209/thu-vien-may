<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    protected $fillable = [
        'reader_id', 'book_id', 'approved_by', 'quantity', 'status',
        'ready_at', 'expires_at', 'fulfilled_at', 'cancelled_at',
        'payment_status', 'payment_method', 'amount', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reader_id')->withTrashed();
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class)->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'reservation_id');
    }
}
