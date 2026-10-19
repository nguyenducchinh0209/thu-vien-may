<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookCartItem extends Model
{
    protected $fillable = ['book_cart_id', 'book_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(BookCart::class, 'book_cart_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
