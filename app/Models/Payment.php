<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'reader_id', 'quote_id', 'reservation_id', 'fine_id', 'purpose', 'method', 'status',
        'amount', 'currency', 'idempotency_key', 'request_fingerprint', 'transfer_snapshot',
        'reported_at', 'confirmed_at', 'confirmed_by', 'receipt_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_snapshot' => 'array',
            'reported_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PaymentQuote::class, 'quote_id');
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reader_id')->withTrashed();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class);
    }
}
