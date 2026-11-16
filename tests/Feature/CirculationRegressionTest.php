<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CirculationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_returned_book_write_off_cannot_reduce_stock_below_active_commitments(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = $this->book('BK900001', 2);
        $loan = Loan::create([
            'reader_id' => $reader->id,
            'issued_by' => $staff->id,
            'issued_at' => now()->subDays(3),
            'due_at' => now()->addDays(11),
            'status' => 'active',
            'reader_name_snapshot' => $reader->name,
            'issuer_name_snapshot' => $staff->name,
            'rental_fee_total' => 5000,
            'rental_fee_received' => 5000,
            'rental_fee_received_at' => now()->subDays(3),
            'rental_fee_received_by' => $staff->id,
            'rental_fee_payment_method' => 'cash',
        ]);
        $item = LoanItem::create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'quantity' => 2,
            'returned_quantity' => 1,
            'written_off_quantity' => 0,
            'rental_fee_per_copy' => 2500,
            'book_title_snapshot' => $book->title,
            'book_code_snapshot' => $book->book_code,
        ]);
        Reservation::create([
            'reader_id' => User::factory()->create(['role' => 'Độc giả'])->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $this->actingAs($staff)->postJson('/staff/fines', [
            'loan_item_id' => $item->id,
            'type' => 'unusable',
            'quantity' => 1,
            'reason' => 'Returned copy is physically unusable',
            'amount' => '1000.00',
            'request_key' => 'write-off-commitment-check',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('books', ['id' => $book->id, 'total_quantity' => 2]);
        $this->assertDatabaseHas('loan_items', ['id' => $item->id, 'written_off_quantity' => 0]);
        $this->assertDatabaseMissing('fines', ['request_key' => 'write-off-commitment-check']);
    }

    public function test_fulfillable_fifo_skips_impossible_and_ineligible_pending_heads(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = $this->book('BK900002', 2);
        Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 3,
            'status' => 'pending',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $lockedReader = User::factory()->create(['role' => 'Độc giả', 'is_locked' => true]);
        Reservation::create([
            'reader_id' => $lockedReader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'pending',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $later = Reservation::create([
            'reader_id' => User::factory()->create(['role' => 'Độc giả'])->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $this->actingAs($staff)->postJson("/staff/reservations/{$later->id}/ready")
            ->assertOk()
            ->assertJsonPath('reservation.status', 'ready');

        $secondBook = $this->book('BK900007', 1);
        $ineligibleRequest = Reservation::create([
            'reader_id' => $lockedReader->id,
            'book_id' => $secondBook->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);
        $this->postJson("/staff/reservations/{$ineligibleRequest->id}/ready")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
        $this->assertDatabaseHas('reservations', ['id' => $ineligibleRequest->id, 'status' => 'pending']);
    }

    public function test_fulfillable_earlier_request_keeps_fifo_when_current_stock_is_temporarily_held(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK900010', 1);
        $earlierReader = User::factory()->create(['role' => 'Độc giả']);
        $laterReader = User::factory()->create(['role' => 'Độc giả']);
        Reservation::create([
            'reader_id' => User::factory()->create(['role' => 'Độc giả'])->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $earlier = Reservation::create([
            'reader_id' => $earlierReader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'pending',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $later = Reservation::create([
            'reader_id' => $laterReader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $this->actingAs($staff)->postJson("/staff/reservations/{$later->id}/ready")
            ->assertUnprocessable()
            ->assertJsonPath('errors.reservation.0', 'Cần xử lý yêu cầu đặt trước cũ hơn trước.');
        $this->assertDatabaseHas('reservations', ['id' => $earlier->id, 'status' => 'pending']);
    }

    public function test_reservation_payment_confirmation_rejects_inactive_or_changed_recipient(): void
    {
        foreach ([
            ['is_locked' => true],
            ['role' => 'Thủ thư'],
            ['deleted' => true],
        ] as $index => $change) {
            $reader = User::factory()->create(['role' => 'Độc giả']);
            $staff = User::factory()->create(['role' => 'Thủ thư']);
            $book = $this->book('BK90001'.($index + 3), 1);
            $reservation = Reservation::create([
                'reader_id' => $reader->id,
                'book_id' => $book->id,
                'quantity' => 1,
                'status' => 'ready',
                'ready_at' => now(),
                'expires_at' => now()->addHours(24),
                'payment_status' => 'unpaid',
                'payment_method' => 'cash',
                'amount' => '5000.00',
                'currency' => 'VND',
            ]);
            $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
                'purpose' => 'reservation',
                'reference_id' => (string) $reservation->id,
            ])->assertOk()->json('quote');
            $payment = $this->postJson('/my/payments', [
                'quote_id' => $quote['id'],
                'method' => 'cash',
                'idempotency_key' => 'inactive-recipient-'.$index,
            ])->assertCreated()->json('payment');

            if (! empty($change['deleted'])) {
                $reader->delete();
            } else {
                $reader->update($change);
            }

            $this->actingAs($staff)->postJson("/staff/payments/{$payment['id']}/confirm", ['method' => 'cash'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('payment');
            $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => 'pending']);
            $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'payment_status' => 'pending']);
        }
    }

    public function test_paid_reservation_confirmation_retry_is_idempotent_after_account_change_but_handover_is_blocked(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK900006', 1);
        $reservation = Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now(),
            'expires_at' => now()->addHours(24),
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'amount' => '5000.00',
            'currency' => 'VND',
        ]);
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservation->id,
        ])->assertOk()->json('quote');
        $payment = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'paid-recipient-retry',
        ])->assertCreated()->json('payment');
        $this->actingAs($staff)->postJson("/staff/payments/{$payment['id']}/confirm", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');

        $this->actingAs($reader)->deleteJson("/reservations/{$reservation->id}")
            ->assertStatus(409);
        $book->delete();
        $this->actingAs($staff);
        $this->postJson("/staff/payments/{$payment['id']}/confirm", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');
        $book->restore();

        $reader->update(['is_locked' => true]);
        $this->postJson("/staff/payments/{$payment['id']}/confirm", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');
        $this->postJson("/staff/reservations/{$reservation->id}/handover", [
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash'],
        ])->assertUnprocessable()->assertJsonValidationErrors('reader_id');
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => 'paid']);
        $this->assertDatabaseMissing('loans', ['reservation_id' => $reservation->id]);
    }

    public function test_paid_handover_retry_returns_existing_loan_after_reader_becomes_ineligible(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK900008', 1);
        $reservation = Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now(),
            'expires_at' => now()->addHours(24),
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'amount' => '5000.00',
            'currency' => 'VND',
        ]);
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservation->id,
        ])->assertOk()->json('quote');
        $payment = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'handover-retry-account-change',
        ])->assertCreated()->json('payment');
        $this->actingAs($staff)->postJson("/staff/payments/{$payment['id']}/confirm", ['method' => 'cash'])->assertOk();
        $handover = ['deposit_payment' => ['amount' => '100000.00', 'method' => 'cash']];
        $firstLoanId = $this->postJson("/staff/reservations/{$reservation->id}/handover", $handover)
            ->assertOk()
            ->json('loan.id');

        $reader->update(['is_locked' => true]);
        $this->postJson("/staff/reservations/{$reservation->id}/handover", $handover)
            ->assertOk()
            ->assertJsonPath('loan.id', $firstLoanId);
        $this->assertDatabaseCount('loans', 1);
    }

    public function test_cancel_request_can_require_its_observed_status_and_keeps_legacy_unpaid_ready_cancellation(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = $this->book('BK900009', 1);
        $reservation = Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $this->actingAs($reader)->deleteJson("/reservations/{$reservation->id}", ['expected_status' => 'pending'])
            ->assertStatus(409);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'ready']);
        $this->deleteJson("/reservations/{$reservation->id}")
            ->assertOk();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'cancelled']);
    }

    private function book(string $code, int $quantity): Book
    {
        return Book::create([
            'book_code' => $code,
            'title' => 'Circulation regression book '.$code,
            'total_quantity' => $quantity,
            'rental_fee' => 5000,
        ]);
    }
}
