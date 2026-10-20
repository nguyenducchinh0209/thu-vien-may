<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentQuote;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyReservationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_ready_reservation_is_quoted_at_current_book_price_without_creating_payment_or_loan(): void
    {
        [$reader, , $book, $reservation] = $this->legacyReservation(quantity: 2, rentalFee: '1250.00');
        $readyAt = $reservation->ready_at->toDateTimeString();
        $expiresAt = $reservation->expires_at->toDateTimeString();

        $this->actingAs($reader)->getJson('/my/reservations/'.$reservation->id)
            ->assertOk()
            ->assertJsonPath('reservation.payment_status', null)
            ->assertJsonPath('reservation.amount', null)
            ->assertJsonPath('payment', null)
            ->assertJsonPath('loan', null);
        $this->assertSame(0, PaymentQuote::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Loan::query()->count());

        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservation->id,
            'amount' => '0.01',
            'total_amount' => '0.01',
        ])->assertOk()
            ->assertJsonPath('quote.purpose', 'reservation')
            ->assertJsonPath('quote.reference_id', (string) $reservation->id)
            ->assertJsonPath('quote.total_amount', '2500.00')
            ->assertJsonPath('quote.currency', 'VND')
            ->assertJsonPath('quote.items.0.book_id', $book->id)
            ->assertJsonPath('quote.items.0.title', $book->title)
            ->assertJsonPath('quote.items.0.quantity', 2)
            ->assertJsonPath('quote.items.0.unit_amount', '1250.00')
            ->assertJsonPath('quote.items.0.line_amount', '2500.00')
            ->json('quote');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'ready',
            'payment_status' => 'unpaid',
            'payment_method' => null,
            'amount' => '2500.00',
            'currency' => 'VND',
            'ready_at' => $readyAt,
            'expires_at' => $expiresAt,
        ]);
        $this->assertDatabaseHas('payment_quotes', [
            'id' => $quote['id'],
            'reader_id' => $reader->id,
            'reservation_id' => $reservation->id,
            'total_amount' => '2500.00',
        ]);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Loan::query()->count());

        $book->update(['rental_fee' => '5000.00']);
        $this->assertDatabaseHas('payment_quotes', ['id' => $quote['id'], 'total_amount' => '2500.00']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'amount' => '2500.00']);
    }

    public function test_only_owned_ready_unexpired_legacy_reservations_can_be_quoted(): void
    {
        [$reader, , , $pending] = $this->legacyReservation(status: 'pending');
        [, , , $expired] = $this->legacyReservation(status: 'ready', expiresAt: now()->subSecond(), reader: $reader);
        [, , , $fulfilled] = $this->legacyReservation(status: 'fulfilled', reader: $reader);
        [, , , $otherReadersReservation] = $this->legacyReservation();
        $before = PaymentQuote::query()->count();

        $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $pending->id,
        ])->assertStatus(409);
        $this->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $expired->id,
        ])->assertStatus(409);
        $this->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $fulfilled->id,
        ])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'Độc giả']))
            ->postJson('/my/payment-quotes', [
                'purpose' => 'reservation',
                'reference_id' => (string) $otherReadersReservation->id,
            ])->assertNotFound();

        foreach ([$pending, $expired, $fulfilled, $otherReadersReservation] as $reservation) {
            $this->assertDatabaseHas('reservations', [
                'id' => $reservation->id,
                'payment_status' => null,
                'payment_method' => null,
                'amount' => null,
                'currency' => null,
            ]);
        }
        $this->assertSame($before, PaymentQuote::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Loan::query()->count());
    }

    public function test_deleted_books_existing_loans_or_linked_payments_do_not_convert_a_legacy_reservation(): void
    {
        [$reader, $staff, $deletedBook, $deletedBookReservation] = $this->legacyReservation();
        $deletedBook->delete();
        [, $loanStaff, , $loanReservation] = $this->legacyReservation();
        Loan::query()->create([
            'reader_id' => $loanReservation->reader_id,
            'issued_by' => $loanStaff->id,
            'issued_at' => now()->subDay(),
            'due_at' => now()->addDays(14),
            'status' => 'active',
            'reservation_id' => $loanReservation->id,
        ]);

        [, , , $linkedPaymentReservation] = $this->legacyReservation();
        $linkedQuote = PaymentQuote::query()->create([
            'id' => (string) Str::ulid(),
            'reader_id' => $linkedPaymentReservation->reader_id,
            'purpose' => 'reservation',
            'reservation_id' => $linkedPaymentReservation->id,
            'total_amount' => '900.00',
            'currency' => 'VND',
            'items_snapshot' => [['book_id' => $linkedPaymentReservation->book_id, 'quantity' => 1, 'unit_amount' => '900.00']],
            'allowed_methods' => ['cash'],
            'expires_at' => now()->addDay(),
        ]);
        $linkedPayment = Payment::query()->create([
            'id' => (string) Str::ulid(),
            'reader_id' => $linkedPaymentReservation->reader_id,
            'quote_id' => $linkedQuote->id,
            'reservation_id' => $linkedPaymentReservation->id,
            'purpose' => 'reservation',
            'method' => 'cash',
            'status' => 'pending',
            'amount' => '900.00',
            'currency' => 'VND',
            'idempotency_key' => 'legacy-linked-payment-'.Str::random(8),
            'request_fingerprint' => hash('sha256', Str::random(20)),
        ]);
        $before = PaymentQuote::query()->count();

        $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $deletedBookReservation->id,
        ])->assertStatus(409);
        $this->actingAs(User::query()->findOrFail($loanReservation->reader_id))
            ->postJson('/my/payment-quotes', [
                'purpose' => 'reservation',
                'reference_id' => (string) $loanReservation->id,
            ])->assertStatus(409);
        $this->actingAs(User::query()->findOrFail($linkedPaymentReservation->reader_id))
            ->postJson('/my/payment-quotes', [
                'purpose' => 'reservation',
                'reference_id' => (string) $linkedPaymentReservation->id,
            ])->assertOk()->assertJsonPath('quote.id', $linkedQuote->id)->assertJsonPath('payment.id', $linkedPayment->id);

        foreach ([$deletedBookReservation, $loanReservation, $linkedPaymentReservation] as $reservation) {
            $this->assertDatabaseHas('reservations', [
                'id' => $reservation->id,
                'payment_status' => null,
                'payment_method' => null,
                'amount' => null,
                'currency' => null,
            ]);
        }
        $this->assertSame($before, PaymentQuote::query()->count());
        $this->assertDatabaseHas('payments', ['id' => $linkedPayment->id, 'status' => 'pending']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Loan::query()->count());
    }

    public function test_quote_is_only_a_quote_until_reader_submits_payment_and_staff_confirms_it(): void
    {
        [$reader, $staff, , $reservation] = $this->legacyReservation(rentalFee: '1800.00');
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservation->id,
        ])->assertOk()->json('quote');
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Loan::query()->count());

        $payment = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'legacy-reservation-checkout-'.Str::random(8),
        ])->assertCreated()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount', '1800.00')
            ->json('payment');
        $this->assertSame(0, Loan::query()->count());

        $this->actingAs($staff)->postJson('/staff/payments/'.$payment['id'].'/confirm', ['method' => 'cash'])
            ->assertOk()->assertJsonPath('payment.status', 'paid');
        $this->assertSame(0, Loan::query()->count());

        $this->postJson('/staff/reservations/'.$reservation->id.'/handover')
            ->assertOk()->assertJsonPath('reservation.status', 'fulfilled');
        $this->assertDatabaseHas('loans', [
            'reservation_id' => $reservation->id,
            'payment_id' => $payment['id'],
            'rental_fee_total' => '1800.00',
        ]);
        $this->assertSame(1, Loan::query()->count());
    }

    private function legacyReservation(
        int $quantity = 1,
        string $rentalFee = '900.00',
        string $status = 'ready',
        ?\Illuminate\Support\Carbon $expiresAt = null,
        ?User $reader = null,
    ): array {
        $reader ??= User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = Book::query()->create([
            'book_code' => 'BK'.fake()->unique()->numerify('######'),
            'title' => 'Legacy checkout test book',
            'total_quantity' => 5,
            'rental_fee' => $rentalFee,
        ]);
        $reservation = Reservation::query()->create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'approved_by' => $staff->id,
            'quantity' => $quantity,
            'status' => $status,
            'ready_at' => $status === 'pending' ? null : now()->subHour(),
            'expires_at' => $expiresAt ?? now()->addDay(),
            'fulfilled_at' => $status === 'fulfilled' ? now()->subMinute() : null,
            'payment_status' => null,
            'payment_method' => null,
            'amount' => null,
            'currency' => null,
        ]);

        return [$reader, $staff, $book, $reservation];
    }
}
