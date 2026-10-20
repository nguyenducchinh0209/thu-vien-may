<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentQuote extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'reader_id', 'purpose', 'reservation_id', 'plan_id', 'total_amount',
        'currency', 'items_snapshot', 'allowed_methods', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'items_snapshot' => 'array',
            'allowed_methods' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reader_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function payment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Payment::class, 'quote_id');
    }
}
