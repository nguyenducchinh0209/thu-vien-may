<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'book_code', 'title', 'isbn', 'description', 'publisher',
        'publication_year', 'edition', 'cover_image_path', 'total_quantity', 'rental_fee',
    ];

    protected function casts(): array
    {
        return [
            'publication_year' => 'integer',
            'total_quantity' => 'integer',
            'rental_fee' => 'decimal:2',
        ];
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(BookReview::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BookComment::class);
    }
}
