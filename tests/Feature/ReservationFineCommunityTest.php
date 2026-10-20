<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCartItem;
use App\Models\BookComment;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationFineCommunityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reader_reservation_is_approved_held_and_fulfilled_as_a_loan(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK300001', 'Reserved test book', 2);

        $reservationResponse = $this->actingAs($reader)
            ->postJson('/reservations', ['book_id' => $book->id, 'quantity' => 2])
            ->assertCreated();
        $reservationId = $reservationResponse->json('reservation.id');

        $this->postJson('/reservations', ['book_id' => $book->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('book_id');

        $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->actingAs($staff)
            ->postJson("/staff/reservations/{$reservationId}/ready")
            ->assertOk()
            ->assertJsonPath('reservation.status', 'ready');

        $reservation = Reservation::findOrFail($reservationId);
        $this->assertSame(now()->addHours(48)->toDateTimeString(), $reservation->expires_at->toDateTimeString());

        $this->getJson('/staff/inventory?q=Reserved')
            ->assertOk()
            ->assertJsonPath('data.0.reserved_quantity', 2)
            ->assertJsonPath('data.0.available_quantity', 0);
        $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->actingAs($reader)->getJson('/my/reservations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reservationId);

        $this->actingAs($staff)
            ->postJson("/staff/reservations/{$reservationId}/fulfill", ['rental_fee_payment' => ['amount' => '10000.00', 'method' => 'cash']])
            ->assertCreated()
            ->assertJsonPath('loan.reader.id', $reader->id)
            ->assertJsonPath('loan.items.0.quantity', 2);

        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'status' => 'fulfilled']);
        $this->assertDatabaseHas('loans', ['reader_id' => $reader->id, 'status' => 'active']);
        $this->assertSame(1, Loan::count());
        $this->getJson('/staff/inventory?q=Reserved')
            ->assertOk()
            ->assertJsonPath('data.0.reserved_quantity', 0)
            ->assertJsonPath('data.0.on_loan_quantity', 2)
            ->assertJsonPath('data.0.available_quantity', 0);
    }

    public function test_expired_reservation_releases_inventory_and_cannot_be_fulfilled(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Admin']);
        $book = $this->book('BK300002', 'Expiring book', 1);
        $reservation = Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now()->subDays(4),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($staff)
            ->getJson('/staff/reservations?status=expired')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reservation->id);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->postJson("/staff/reservations/{$reservation->id}/fulfill", ['rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation_id');
        $this->getJson('/staff/inventory?q=Expiring')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 1);
    }

    public function test_reservations_are_approved_in_fifo_order(): void
    {
        $firstReader = User::factory()->create(['role' => 'Độc giả']);
        $secondReader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Admin']);
        $book = $this->book('BK300007', 'FIFO book', 2);

        $firstReservation = $this->actingAs($firstReader)
            ->postJson('/reservations', ['book_id' => $book->id, 'quantity' => 1])
            ->assertCreated()
            ->json('reservation.id');
        $secondReservation = $this->actingAs($secondReader)
            ->postJson('/reservations', ['book_id' => $book->id, 'quantity' => 1])
            ->assertCreated()
            ->json('reservation.id');

        $this->actingAs($staff)
            ->postJson("/staff/reservations/{$secondReservation}/ready")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
        $this->postJson("/staff/reservations/{$firstReservation}/ready")
            ->assertOk();
        $this->postJson("/staff/reservations/{$secondReservation}/ready")
            ->assertOk();

        $this->assertDatabaseHas('reservations', ['id' => $firstReservation, 'status' => 'ready']);
        $this->assertDatabaseHas('reservations', ['id' => $secondReservation, 'status' => 'ready']);
    }

    public function test_reader_cart_merges_items_and_converts_to_reservations_atomically(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = $this->book('BK300005', 'Cart book', 3);
        $secondBook = $this->book('BK300006', 'Second cart book', 1);

        $this->actingAs($reader)
            ->postJson('/my/cart/items', ['book_id' => $book->id, 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('item.quantity', 1);
        $this->postJson('/my/cart/items', ['book_id' => $book->id, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('item.quantity', 3);
        $this->postJson('/my/cart/items', ['book_id' => $secondBook->id, 'quantity' => 1])
            ->assertCreated();

        $cart = $this->getJson('/my/cart')
            ->assertOk()
            ->assertJsonCount(2, 'cart.items')
            ->assertJsonPath('cart.items.0.book.title', 'Cart book')
            ->assertJsonPath('cart.items.0.book.rental_fee', '5000.00')
            ->json('cart.items');

        $this->postJson('/my/cart/reservations')
            ->assertCreated()
            ->assertJsonCount(2, 'reservations');

        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseHas('reservations', [
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 3,
            'status' => 'pending',
        ]);
        $this->assertSame(0, BookCartItem::count());
        $this->assertCount(2, $cart);
    }

    public function test_late_returns_create_unpaid_fines_and_admin_can_waive_them(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK300003', 'Overdue book', 2);

        $loanResponse = $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 2]],
            'rental_fee_payment' => ['amount' => '10000.00', 'method' => 'cash'],
        ])->assertCreated();
        $loanId = $loanResponse->json('loan.id');
        $loanItemId = $loanResponse->json('loan.items.0.id');

        Carbon::setTestNow('2026-10-16 10:00:01');
        $this->actingAs($reader)->getJson('/my/loans')
            ->assertOk()
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.days_overdue', 1);

        $this->actingAs($staff);
        $returnResponse = $this->postJson("/staff/loans/{$loanId}/returns", [
            'items' => [['loan_item_id' => $loanItemId, 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('fines.0.amount', '6000.00');

        $fineId = $returnResponse->json('fines.0.id');
        $this->assertDatabaseHas('fines', [
            'id' => $fineId,
            'reader_id' => $reader->id,
            'overdue_days' => 1,
            'quantity' => 2,
            'daily_rate' => 3000,
            'status' => 'unpaid',
        ]);

        $this->actingAs($reader)->getJson('/my/fines')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fineId)
            ->assertJsonPath('data.0.amount', '6000.00');

        $this->actingAs($staff)
            ->patchJson("/staff/fines/{$fineId}/waive", ['reason' => 'Duyệt miễn do sự cố'])
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'Admin']);
        $this->actingAs($admin)
            ->patchJson("/staff/fines/{$fineId}/waive", ['reason' => 'Duyệt miễn do sự cố'])
            ->assertOk()
            ->assertJsonPath('fine.status', 'waived');

        $this->assertDatabaseHas('fines', [
            'id' => $fineId,
            'status' => 'waived',
            'waived_by' => $admin->id,
        ]);
    }

    public function test_readers_can_review_comment_and_suggest_books_with_ownership_protection(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $otherReader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK300004', 'Community book', 1);

        $reviewResponse = $this->actingAs($reader)
            ->postJson("/books/{$book->id}/reviews", [
                'rating' => 5,
                'title' => 'Rat hay',
                'body' => 'Noi dung huu ich.',
            ])
            ->assertCreated();
        $reviewId = $reviewResponse->json('review.id');

        $this->postJson("/books/{$book->id}/reviews", ['rating' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');
        $this->patchJson("/reviews/{$reviewId}", ['rating' => 3])
            ->assertOk()
            ->assertJsonPath('review.rating', 3);

        $this->postJson("/books/{$book->id}/comments", ['body' => 'Binh luan thu nghiem'])
            ->assertCreated();
        $comment = BookComment::firstOrFail();

        $this->actingAs($otherReader)
            ->deleteJson("/comments/{$comment->id}")
            ->assertNotFound();
        $this->actingAs($reader)
            ->deleteJson("/comments/{$comment->id}")
            ->assertOk();

        $this->getJson("/books/{$book->id}/reviews")
            ->assertOk()
            ->assertJsonPath('average_rating', 3)
            ->assertJsonPath('review_count', 1);

        $suggestionResponse = $this->postJson('/book-suggestions', [
            'title' => 'A Suggested Book',
            'author_name' => 'Suggested Author',
            'reason' => 'Readers requested this topic.',
        ])->assertCreated();
        $suggestionId = $suggestionResponse->json('suggestion.id');

        $this->actingAs($staff)
            ->getJson('/staff/book-suggestions?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $suggestionId);

        $this->patchJson("/staff/book-suggestions/{$suggestionId}", [
            'status' => 'approved',
            'staff_note' => 'Will be considered for purchase.',
        ])
            ->assertOk()
            ->assertJsonPath('suggestion.status', 'approved');

        $this->assertDatabaseHas('book_suggestions', [
            'id' => $suggestionId,
            'user_id' => $reader->id,
            'reviewed_by' => $staff->id,
            'status' => 'approved',
        ]);
    }

    private function book(string $code, string $title, int $quantity): Book
    {
        return Book::create([
            'book_code' => $code,
            'title' => $title,
            'total_quantity' => $quantity,
            'rental_fee' => 5000,
        ]);
    }
}
