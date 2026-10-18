<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LibraryFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_repeatable_and_preserves_existing_passwords(): void
    {
        $this->seed();
        $user = User::where('email', 'minhanh@example.com')->firstOrFail();
        $user->password = 'changed-password';
        $user->save();
        $this->seed();
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('reader_profiles', 1);
        $this->assertDatabaseCount('books', 3);
        $this->assertDatabaseCount('author_book', 3);
        $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
        $this->assertSame($user->id, $user->readerProfile->user->id);
    }

    public function test_registration_creates_reader_profile(): void
    {
        $this->postJson('/auth/register', [
            'name' => 'Example Reader', 'email' => 'reader@example.test',
            'password' => 'example-password', 'password_confirmation' => 'example-password',
        ])->assertCreated();
        $user = User::where('email', 'reader@example.test')->firstOrFail();
        $this->assertNotNull($user->readerProfile);
        $this->assertSame('RD'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT), $user->readerProfile->reader_code);
    }

    public function test_multiple_authors_and_soft_deletion_preserve_catalog(): void
    {
        $this->seed();
        $book = Book::where('book_code', 'BK000001')->firstOrFail();
        $author = Author::create(['name' => 'Second Demo Author']);
        $book->authors()->attach($author);
        $this->assertCount(2, $book->authors);
        $this->assertTrue($author->books->contains($book));
        $this->assertSame('5000.00', $book->rental_fee);
        $book->delete();
        $this->assertNull(Book::find($book->id));
        $this->assertDatabaseHas('author_book', ['book_id' => $book->id, 'author_id' => $author->id]);
        $book->restore();
        $this->assertNotNull(Book::find($book->id));
    }

    public function test_duplicate_book_code_is_rejected(): void
    {
        $this->seed();
        $this->expectException(QueryException::class);
        Book::create(['book_code' => 'BK000001', 'title' => 'Duplicate']);
    }

    public function test_reader_with_profile_cannot_be_hard_deleted(): void
    {
        $this->seed();
        $this->expectException(QueryException::class);
        User::where('email', 'minhanh@example.com')->firstOrFail()->delete();
    }
}
