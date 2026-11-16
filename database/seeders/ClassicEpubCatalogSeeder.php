<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ClassicEpubCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $assets = database_path('demo-catalog');
        $titles = json_decode(file_get_contents($assets.'/catalog.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($titles as $title) {
            DB::transaction(function () use ($assets, $title): void {
                if (Book::withTrashed()->where('book_code', $title['code'])->exists()) {
                    return;
                }

                $coverPath = 'book-covers/'.$title['cover'];
                $epubPath = 'book-epubs/catalog/'.$title['epub'];
                $this->copyAsset('public', $coverPath, $assets.'/'.$title['cover']);
                $this->copyAsset('local', $epubPath, $assets.'/'.$title['epub']);

                $book = Book::create([
                    'book_code' => $title['code'],
                    'title' => $title['title'],
                    'category' => $title['category'],
                    'edition' => $title['edition'],
                    'description' => $title['description'],
                    'cover_image_path' => $coverPath,
                    'epub_path' => $epubPath,
                    'total_quantity' => 5,
                    'rental_fee' => $title['rental_fee'],
                ]);
                $author = Author::firstOrCreate(['name' => $title['author']]);
                $book->authors()->attach($author);
            });
        }
    }

    private function copyAsset(string $disk, string $path, string $source): void
    {
        $storage = Storage::disk($disk);
        if ($storage->exists($path)) {
            return;
        }

        $contents = file_get_contents($source);
        if ($contents === false || ! $storage->put($path, $contents)) {
            throw new RuntimeException('Không lưu được tệp dữ liệu mẫu '.$path);
        }
    }
}
