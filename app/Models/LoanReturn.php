<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanReturn extends Model
{
    protected $fillable = ['loan_id', 'returned_by', 'returned_at'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanReturnItem::class);
    }
}
