<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationPaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reservation_quote_uses_server_price_and_payment_is_owned_and_idempotent(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $otherReader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK500001', 'Payment quote book', 2, 7000);
        $reservationId = $this->reserve($reader, $book, 2);

        $this->actingAs($staff)->postJson("/staff/reservations/{$reservationId}/approve")
            ->assertOk();

        $this->postJson('/staff/loans', [
            'reservation_id' => $reservationId,
            'rental_fee_payment' => ['amount' => '14000.00', 'method' => 'cash'],
        ])->assertUnprocessable()->assertJsonValidationErrors('reservation_id');

        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservationId,
            'total_amount' => 1,
        ])->assertOk()
            ->assertJsonPath('quote.total_amount', '14000.00')
            ->assertJsonPath('quote.currency', 'VND')
            ->assertJsonPath('quote.items.0.quantity', 2)
            ->json('quote');

        $payload = [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'reservation-cash-1',
        ];
        $paymentResponse = $this->postJson('/my/payments', $payload)
            ->assertCreated()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.method', 'cash');
        $paymentId = $paymentResponse->json('payment.id');

        $this->assertNull($paymentResponse->json('payment.transfer'));
        $this->postJson('/my/payments', $payload)
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId);
        $sameReservationQuote = $this->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservationId,
        ])->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->json('quote');
        $this->postJson('/my/payments', [
            'quote_id' => $sameReservationQuote['id'],
            'method' => 'cash',
            'idempotency_key' => 'reservation-cash-second-key',
        ])->assertOk()->assertJsonPath('payment.id', $paymentId);
        $this->postJson('/my/payments', [...$payload, 'method' => 'bank_transfer'])
            ->assertStatus(409);

        $this->actingAs($otherReader)->getJson("/my/payments/{$paymentId}")->assertNotFound();
        $this->actingAs($reader)->getJson("/my/payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('payment.status', 'pending');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'reader_id' => $reader->id,
            'reservation_id' => $reservationId,
            'amount' => '14000.00',
            'status' => 'pending',
        ]);
        $this->assertSame(1, Payment::where('reservation_id', $reservationId)->count());
    }

    public function test_demo_bank_transfer_requires_staff_confirmation_and_handover_uses_paid_snapshot_once(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK500002', 'Snapshot handover book', 2, 5000);
        $reservationId = $this->reserve($reader, $book, 2);

        $this->actingAs($staff)->postJson("/staff/reservations/{$reservationId}/approve")
            ->assertOk();
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservationId,
        ])->assertOk()->json('quote');
        $book->update(['rental_fee' => 9000]);

        $payment = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'bank_transfer',
            'idempotency_key' => 'reservation-demo-transfer-1',
        ])->assertCreated()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.transfer.simulated', true)
            ->assertJsonPath('payment.transfer.account_number', 'DEMO-LIBRARY-0001');
        $paymentId = $payment->json('payment.id');
        $this->assertNull($payment->json('payment.transfer.qr_url'));

        $this->postJson("/my/payments/{$paymentId}/transfer")
            ->assertOk()
            ->assertJsonPath('payment.status', 'confirming');
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $paymentId,
            'from_status' => 'pending',
            'to_status' => 'confirming',
            'event_type' => 'transfer_reported',
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'payment_status' => 'confirming',
            'amount' => '10000.00',
        ]);

        $this->actingAs($staff)->getJson('/staff/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paymentId);
        $this->postJson("/staff/payments/{$paymentId}/confirm", ['method' => 'bank_transfer'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $paymentId,
            'from_status' => 'confirming',
            'to_status' => 'paid',
            'event_type' => 'staff_confirmed',
            'actor_id' => $staff->id,
        ]);
        $this->postJson("/staff/payments/{$paymentId}/confirm", ['method' => 'bank_transfer'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');

        $this->postJson('/staff/loans', [
            'reservation_id' => $reservationId,
            'rental_fee_payment' => ['amount' => '10000.00', 'method' => 'cash'],
        ])->assertUnprocessable()->assertJsonValidationErrors('reservation_id');

        $loanId = $this->postJson("/staff/reservations/{$reservationId}/handover")
            ->assertOk()
            ->assertJsonPath('loan.reservation_id', $reservationId)
            ->json('loan.id');
        $this->postJson("/staff/reservations/{$reservationId}/handover")
            ->assertOk()
            ->assertJsonPath('loan.id', $loanId);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'paid',
            'amount' => '10000.00',
        ]);
        $this->assertDatabaseHas('loans', [
            'reservation_id' => $reservationId,
            'payment_id' => $paymentId,
            'rental_fee_total' => '10000.00',
            'rental_fee_received' => '10000.00',
            'rental_fee_payment_method' => 'bank_transfer',
        ]);
        $this->assertDatabaseHas('loan_items', [
            'book_id' => $book->id,
            'quantity' => 2,
            'rental_fee_per_copy' => '5000.00',
        ]);
        $this->assertSame(1, Payment::where('reservation_id', $reservationId)->count());
    }

    public function test_vip_plan_costs_two_hundred_thousand_and_renews_by_one_calendar_month(): void
    {
        Carbon::setTestNow('2026-01-31 10:00:00');
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Admin']);

        $this->getJson('/membership/plans')
            ->assertOk()
            ->assertJsonPath('plans.0.id', 'vip-month')
            ->assertJsonPath('plans.0.price', '200000.00')
            ->assertJsonPath('plans.0.duration_label', '1 tháng');
        $this->actingAs($reader)->getJson('/my/membership')
            ->assertOk()
            ->assertJsonPath('membership.tier', 'free')
            ->assertJsonPath('membership.status', 'active');

        $firstPaymentId = $this->createAndConfirmVipPayment($reader, $staff, 'vip-renewal-1');
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $firstPaymentId,
            'from_status' => 'pending',
            'to_status' => 'paid',
            'event_type' => 'staff_confirmed',
            'actor_id' => $staff->id,
        ]);
        $membership = Membership::where('user_id', $reader->id)->firstOrFail();
        $this->assertSame('2026-02-28 10:00:00', $membership->expires_at->toDateTimeString());

        $this->postJson("/staff/payments/{$firstPaymentId}/confirm", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');
        $this->assertSame('2026-02-28 10:00:00', $membership->fresh()->expires_at->toDateTimeString());

        $secondPaymentId = $this->createAndConfirmVipPayment($reader, $staff, 'vip-renewal-2');
        $this->assertSame('2026-03-28 10:00:00', $membership->fresh()->expires_at->toDateTimeString());
        $this->assertSame(2, Payment::where('reader_id', $reader->id)->where('purpose', 'vip')->where('status', 'paid')->count());

        $this->actingAs($reader)->getJson('/my/membership')
            ->assertOk()
            ->assertJsonPath('membership.tier', 'vip')
            ->assertJsonPath('membership.status', 'active');
        $this->assertDatabaseHas('payments', ['id' => $secondPaymentId, 'status' => 'paid', 'amount' => '200000.00']);
    }

    public function test_expired_reservation_quote_cannot_create_payment(): void
    {
        Carbon::setTestNow('2026-02-01 09:00:00');
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK500003', 'Expired quote book', 1, 5000);
        $reservationId = $this->reserve($reader, $book, 1);

        $this->actingAs($staff)->postJson("/staff/reservations/{$reservationId}/approve")
            ->assertOk();
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'reservation',
            'reference_id' => (string) $reservationId,
        ])->assertOk()->json('quote');

        Carbon::setTestNow('2026-02-03 09:00:01');
        $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'expired-reservation-payment',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('payments', ['quote_id' => $quote['id']]);
    }

    public function test_explicit_payment_method_starts_the_new_reservation_payment_workflow(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK500005', 'Explicit payment method book', 1, 5000);

        $reservationId = $this->actingAs($reader)->postJson('/reservations', [
            'book_id' => $book->id,
            'quantity' => 1,
            'payment_method' => 'bank_transfer',
        ])->assertCreated()
            ->assertJsonPath('reservation.payment_status', 'unpaid')
            ->assertJsonPath('reservation.payment_method', 'bank_transfer')
            ->json('reservation.id');

        $this->actingAs($staff)->postJson("/staff/reservations/{$reservationId}/ready")
            ->assertOk();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'payment_status' => 'unpaid',
            'payment_method' => 'bank_transfer',
            'amount' => '5000.00',
        ]);
    }

    public function test_staff_payment_queue_handles_a_soft_deleted_reader(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'vip',
            'reference_id' => 'vip-month',
        ])->assertOk()->json('quote');
        $paymentId = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'deleted-reader-payment',
        ])->assertCreated()->json('payment.id');

        $reader->delete();

        $this->actingAs($staff)->getJson('/staff/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paymentId);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'pending']);
    }

    public function test_same_vip_quote_with_a_new_idempotency_key_never_creates_a_second_payment(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'vip',
            'reference_id' => 'vip-month',
        ])->assertOk()->json('quote');
        $first = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'vip-quote-first-key',
        ])->assertCreated();
        $paymentId = $first->json('payment.id');

        $retry = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'vip-quote-second-key',
        ]);

        $this->assertContains($retry->status(), [200, 409], $retry->getContent());
        if ($retry->status() === 200) {
            $retry->assertJsonPath('payment.id', $paymentId);
        }
        $this->assertSame(1, Payment::where('quote_id', $quote['id'])->count());
    }

    public function test_legacy_reservation_fulfillment_remains_available_without_payment_workflow(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book('BK500004', 'Legacy fulfillment book', 2, 5000);
        $readyReservation = Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'ready',
            'approved_by' => $staff->id,
            'ready_at' => now(),
            'expires_at' => now()->addHours(48),
        ]);
        $this->actingAs($staff)->postJson("/staff/reservations/{$readyReservation->id}/fulfill", [
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
        ])->assertCreated();

        $pendingReservation = Reservation::create([
            'reader_id' => $reader->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);
        $reservationId = $pendingReservation->id;

        $this->actingAs($staff)->postJson("/staff/reservations/{$reservationId}/ready")
            ->assertOk();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'ready',
            'payment_status' => null,
        ]);
        $this->postJson("/staff/reservations/{$reservationId}/fulfill", [
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
        ])->assertCreated();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'fulfilled',
            'payment_status' => null,
        ]);
        $this->assertSame(0, Payment::whereIn('reservation_id', [$readyReservation->id, $reservationId])->count());
    }

    private function createAndConfirmVipPayment(User $reader, User $staff, string $idempotencyKey): string
    {
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'vip',
            'reference_id' => 'vip-month',
        ])->assertOk()
            ->assertJsonPath('quote.total_amount', '200000.00')
            ->json('quote');
        $paymentId = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated()
            ->assertJsonPath('payment.status', 'pending')
            ->json('payment.id');

        $this->actingAs($staff)->getJson('/staff/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paymentId);
        $this->postJson("/staff/payments/{$paymentId}/confirm", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');

        return $paymentId;
    }

    private function reserve(User $reader, Book $book, int $quantity): int
    {
        return $this->actingAs($reader)
            ->postJson('/reservations', ['book_id' => $book->id, 'quantity' => $quantity])
            ->assertCreated()
            ->json('reservation.id');
    }

    private function book(string $code, string $title, int $quantity, int $rentalFee): Book
    {
        return Book::create([
            'book_code' => $code,
            'title' => $title,
            'total_quantity' => $quantity,
            'rental_fee' => $rentalFee,
        ]);
    }
}
