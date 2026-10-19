<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanItem extends Model
{
    protected $fillable = ['loan_id', 'book_id', 'quantity', 'returned_quantity', 'rental_fee_per_copy'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
            'rental_fee_per_copy' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class)->withTrashed();
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(LoanReturnItem::class);
    }
}
