<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_list_update_and_soft_delete_a_book(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Admin']));
        $author = Author::create(['name' => 'Nguyen Van A']);

        $this->postJson('/staff/books', $this->bookData([
            'author_ids' => [$author->id],
        ]))
            ->assertCreated()
            ->assertJsonPath('book.book_code', 'BK100001')
            ->assertJsonPath('book.authors.0.id', $author->id);

        $book = Book::where('book_code', 'BK100001')->firstOrFail();

        $this->getJson('/staff/books?q=Example')
            ->assertOk()
            ->assertJsonPath('data.0.id', $book->id);

        $this->patchJson("/staff/books/{$book->id}", ['title' => 'Updated title'])
            ->assertOk()
            ->assertJsonPath('book.title', 'Updated title');

        $this->deleteJson("/staff/books/{$book->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Đã xóa sách.');

        $this->assertSoftDeleted('books', ['id' => $book->id]);
    }

    public function test_invalid_book_data_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Admin']));

        $this->postJson('/staff/books', $this->bookData([
            'book_code' => '',
            'total_quantity' => -1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_code', 'total_quantity']);
    }

    public function test_reader_cannot_manage_books(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Độc giả']));

        $this->postJson('/staff/books', $this->bookData())->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function bookData(array $overrides = []): array
    {
        return array_replace([
            'book_code' => 'BK100001',
            'title' => 'Example Book',
            'isbn' => '9780000000001',
            'description' => 'A test book.',
            'publisher' => 'Example Publisher',
            'publication_year' => 2020,
            'edition' => 'First edition',
            'total_quantity' => 5,
            'rental_fee' => 5000,
        ], $overrides);
    }
}
