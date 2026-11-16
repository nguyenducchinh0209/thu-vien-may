<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Database\Seeder;

class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        // Fictional catalog data for local development only.
        $author = Author::firstOrCreate(['name' => 'Demo Author']);
        foreach ([
            ['BK000001', 'Library Demo Book One', 5],
            ['BK000002', 'Library Demo Book Two', 3],
            ['BK000003', 'Library Demo Out of Stock', 0],
        ] as [$code, $title, $quantity]) {
            $book = Book::firstOrCreate(['book_code' => $code], [
                'title' => $title,
                'description' => 'Fictional sample for development.',
                'total_quantity' => $quantity,
                'rental_fee' => '5000.00',
            ]);
            $book->authors()->syncWithoutDetaching([$author->id]);
        }
    }
}
