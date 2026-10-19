<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_staff_can_issue_multi_book_loan_and_record_partial_returns(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create([
            'book_code' => 'BK200001',
            'title' => 'Loan Test Book',
            'total_quantity' => 3,
            'rental_fee' => 5000,
        ]);
        $secondBook = Book::create([
            'book_code' => 'BK200002',
            'title' => 'Second Loan Test Book',
            'total_quantity' => 1,
            'rental_fee' => 2500,
        ]);

        $this->actingAs($staff)
            ->postJson('/staff/loans', [
                'reader_id' => $reader->id,
                'items' => [
                    ['book_id' => $book->id, 'quantity' => 2],
                    ['book_id' => $secondBook->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('loan.status', 'active')
            ->assertJsonPath('loan.items.0.rental_fee_per_copy', '5000.00');

        $loan = Loan::with('items')->firstOrFail();
        $this->assertSame('2026-11-01 10:00:00', $loan->due_at->format('Y-m-d H:i:s'));

        $this->getJson('/staff/inventory?q=Loan Test Book')
            ->assertOk()
            ->assertJsonPath('data.0.total_quantity', 3)
            ->assertJsonPath('data.0.on_loan_quantity', 2)
            ->assertJsonPath('data.0.available_quantity', 1);

        $this->actingAs(User::factory()->create(['role' => 'Admin']))
            ->postJson("/staff/loans/{$loan->id}/returns", [
                'items' => [
                    ['loan_item_id' => $loan->items[0]->id, 'quantity' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('loan.status', 'active');

        $this->getJson('/staff/loans')
            ->assertOk()
            ->assertJsonPath('data.0.returns.0.items.0.quantity', 1)
            ->assertJsonPath('data.0.returns.0.items.0.loan_item.book.title', 'Loan Test Book');

        $this->getJson('/staff/inventory?q=Loan Test Book')
            ->assertOk()
            ->assertJsonPath('data.0.on_loan_quantity', 1)
            ->assertJsonPath('data.0.available_quantity', 2);

        $this->postJson("/staff/loans/{$loan->id}/returns", [
            'items' => [
                ['loan_item_id' => $loan->items[0]->id, 'quantity' => 1],
                ['loan_item_id' => $loan->items[1]->id, 'quantity' => 1],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('loan.status', 'returned');

        $this->assertDatabaseCount('loan_returns', 2);
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'returned']);
        $this->getJson('/staff/inventory?q=Loan Test Book')
            ->assertOk()
            ->assertJsonPath('data.0.on_loan_quantity', 0)
            ->assertJsonPath('data.0.available_quantity', 3);

    }

    public function test_issuing_more_than_available_and_over_returning_are_rejected(): void
    {
        $staff = User::factory()->create(['role' => 'Admin']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create([
            'book_code' => 'BK200003',
            'title' => 'Limited Book',
            'total_quantity' => 1,
            'rental_fee' => 5000,
        ]);

        $this->actingAs($staff);
        $this->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 2]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
        ])->assertCreated();

        $loan = Loan::with('items')->firstOrFail();
        $this->postJson("/staff/loans/{$loan->id}/returns", [
            'items' => [['loan_item_id' => $loan->items[0]->id, 'quantity' => 2]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertDatabaseCount('loan_returns', 0);
        $this->assertDatabaseHas('loan_items', ['id' => $loan->items[0]->id, 'returned_quantity' => 0]);
    }

    public function test_readers_cannot_issue_or_return_books(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)
            ->getJson('/staff/loans')
            ->assertForbidden();

        $this->assertSame(0, LoanReturn::count());
    }
}
