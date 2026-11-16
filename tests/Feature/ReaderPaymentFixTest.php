<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Fine;
use App\Models\FinePayment;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\LoanReturn;
use App\Models\LoanReturnItem;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentQuote;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReaderPaymentFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_reader_can_view_owned_reservation_states_without_creating_a_quote_or_loan(): void
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $otherReader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book();
        $reservations = [];
        foreach (['pending', 'ready', 'expired', 'fulfilled'] as $status) {
            $reservations[$status] = Reservation::query()->create([
                'reader_id' => $reader->id,
                'book_id' => $book->id,
                'approved_by' => $staff->id,
                'quantity' => 1,
                'status' => $status,
                'ready_at' => $status === 'pending' ? null : now()->subDay(),
                'expires_at' => $status === 'expired' ? now()->subSecond() : now()->addDay(),
                'fulfilled_at' => $status === 'fulfilled' ? now() : null,
                'payment_status' => $status === 'fulfilled' ? 'paid' : null,
                'payment_method' => $status === 'fulfilled' ? 'cash' : null,
                'amount' => $status === 'fulfilled' ? '1200.00' : null,
                'currency' => $status === 'fulfilled' ? 'VND' : null,
            ]);
        }
        $this->attachPaidReservationSnapshot($reservations['fulfilled'], $reader, $staff, $book);

        $quoteCount = PaymentQuote::query()->count();
        $paymentCount = Payment::query()->count();
        $loanCount = Loan::query()->count();
        foreach ($reservations as $status => $reservation) {
            $response = $this->actingAs($reader)->getJson('/my/reservations/'.$reservation->id)
                ->assertOk()
                ->assertJsonPath('reservation.id', $reservation->id)
                ->assertJsonPath('reservation.status', $status)
                ->assertJsonPath('reservation.book.title', $book->title);
            if ($status === 'fulfilled') {
                $response->assertJsonPath('payment.status', 'paid');
                $this->assertEquals(1200, (float) $response->json('loan.rental_fee_received'));
            }
        }

        $this->actingAs($otherReader)->getJson('/my/reservations/'.$reservations['pending']->id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/my/reservations/'.$reservations['pending']->id)->assertUnauthorized();
        $this->assertSame($quoteCount, PaymentQuote::query()->count());
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($loanCount, Loan::query()->count());
    }

    public function test_fine_checkout_uses_server_amount_and_staff_confirmation_records_one_fine_payment(): void
    {
        [$reader, $staff, $fine] = $this->fineFixture('2500.00');
        $otherReader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($otherReader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
        ])->assertNotFound();

        $this->actingAs($reader);
        $quoteResponse = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
            'amount' => '0.01',
            'total_amount' => '0.01',
        ])->assertOk()->assertJsonPath('quote.purpose', 'fine')
            ->assertJsonPath('quote.total_amount', '2500.00');
        $quote = $quoteResponse->json('quote');

        $paymentResponse = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-cash-once',
            'amount' => '0.01',
        ])->assertCreated()->assertJsonPath('payment.purpose', 'fine')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount', '2500.00');
        $paymentId = $paymentResponse->json('payment.id');
        $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-cash-once',
        ])->assertOk()->assertJsonPath('payment.id', $paymentId);

        $this->actingAs($reader)->postJson('/staff/payments/'.$paymentId.'/confirm', ['method' => 'cash'])->assertForbidden();
        $this->actingAs($staff)->postJson('/staff/payments/'.$paymentId.'/confirm', ['method' => 'cash'])
            ->assertOk()->assertJsonPath('payment.status', 'paid');
        $this->assertDatabaseHas('fines', ['id' => $fine->id, 'status' => 'paid', 'amount' => '2500.00']);
        $this->assertDatabaseHas('fine_payments', ['fine_id' => $fine->id, 'amount' => '2500.00', 'method' => 'cash']);

        $this->postJson('/staff/payments/'.$paymentId.'/confirm', ['method' => 'cash'])->assertOk();
        $this->postJson('/staff/fines/'.$fine->id.'/payments', [
            'amount' => '2500.00',
            'method' => 'manual',
        ])->assertUnprocessable();
        $this->assertSame(1, FinePayment::query()->where('fine_id', $fine->id)->count());
        $this->assertSame(0, Membership::query()->where('user_id', $reader->id)->count());
        $this->actingAs($reader)->postJson('/my/payment-quotes', ['purpose' => 'fine', 'reference_id' => (string) $fine->id])
            ->assertStatus(409);
    }

    public function test_bank_transfer_fine_payment_is_reported_then_staff_confirmed_once(): void
    {
        [$reader, $staff, $fine] = $this->fineFixture('3400.00');
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
        ])->assertOk()->assertJsonPath('quote.total_amount', '3400.00')->json('quote');
        $payment = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'bank_transfer',
            'idempotency_key' => 'fine-bank-transfer-1',
        ])->assertCreated()->json('payment');

        $this->postJson('/my/payments/'.$payment['id'].'/transfer')->assertOk()->assertJsonPath('payment.status', 'confirming');
        $this->actingAs($staff)->postJson('/staff/payments/'.$payment['id'].'/confirm', ['method' => 'bank_transfer'])
            ->assertOk()->assertJsonPath('payment.status', 'paid');
        $this->assertDatabaseHas('fines', ['id' => $fine->id, 'status' => 'paid', 'amount' => '3400.00']);
        $this->assertDatabaseHas('fine_payments', ['fine_id' => $fine->id, 'amount' => '3400.00', 'method' => 'bank_transfer']);
        $this->assertSame(1, FinePayment::query()->where('fine_id', $fine->id)->count());
        $this->assertSame(0, Membership::query()->where('user_id', $reader->id)->count());
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_fine_quote(): void
    {
        [$reader, , $firstFine] = $this->fineFixture('2100.00');
        $secondFine = $this->fineFixtureForReader($reader, '3100.00');
        $firstQuote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $firstFine->id,
        ])->assertOk()->json('quote');
        $secondQuote = $this->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $secondFine->id,
        ])->assertOk()->json('quote');

        $this->postJson('/my/payments', [
            'quote_id' => $firstQuote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-cross-quote-key',
        ])->assertCreated();

        $this->postJson('/my/payments', [
            'quote_id' => $secondQuote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-cross-quote-key',
        ])->assertStatus(409);
        $this->assertSame(1, Payment::query()->where('reader_id', $reader->id)->count());
    }

    public function test_expired_fine_attempt_is_audited_and_a_new_payment_can_be_created(): void
    {
        [$reader, , $fine] = $this->fineFixture('2100.00');
        $oldQuote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
        ])->assertOk()->json('quote');
        $oldPayment = $this->postJson('/my/payments', [
            'quote_id' => $oldQuote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-expired-attempt-old',
        ])->assertCreated()->json('payment');
        PaymentQuote::query()->whereKey($oldQuote['id'])->update(['expires_at' => now()->subSecond()]);

        $newQuote = $this->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
        ])->assertOk()->json('quote');
        $this->assertNotSame($oldQuote['id'], $newQuote['id']);
        $this->assertDatabaseHas('payments', ['id' => $oldPayment['id'], 'status' => 'expired', 'idempotency_key' => 'fine-expired-attempt-old']);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $oldPayment['id'], 'event_type' => 'quote_expired']);

        $newPayment = $this->postJson('/my/payments', [
            'quote_id' => $newQuote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-expired-attempt-new',
        ])->assertCreated()->json('payment');
        $this->assertNotSame($oldPayment['id'], $newPayment['id']);
        $this->assertDatabaseHas('payments', ['id' => $oldPayment['id'], 'status' => 'expired']);
        $this->assertDatabaseHas('payments', ['id' => $newPayment['id'], 'status' => 'pending', 'fine_id' => $fine->id]);
    }

    public function test_failed_and_cancelled_fine_attempts_allow_a_new_quote_without_rewriting_history(): void
    {
        foreach (['failed', 'cancelled'] as $oldStatus) {
            [$reader, , $fine] = $this->fineFixture('1700.00');
            $oldQuote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
                'purpose' => 'fine',
                'reference_id' => (string) $fine->id,
            ])->assertOk()->json('quote');
            $oldPayment = $this->postJson('/my/payments', [
                'quote_id' => $oldQuote['id'],
                'method' => 'cash',
                'idempotency_key' => 'fine-terminal-'.$oldStatus,
            ])->assertCreated()->json('payment');
            Payment::query()->whereKey($oldPayment['id'])->update(['status' => $oldStatus]);

            $newQuote = $this->postJson('/my/payment-quotes', [
                'purpose' => 'fine',
                'reference_id' => (string) $fine->id,
            ])->assertOk()->json('quote');
            $this->assertNotSame($oldQuote['id'], $newQuote['id']);
            $this->assertDatabaseHas('payments', ['id' => $oldPayment['id'], 'status' => $oldStatus]);
            $this->postJson('/my/payments', [
                'quote_id' => $newQuote['id'],
                'method' => 'cash',
                'idempotency_key' => 'fine-terminal-retry-'.$oldStatus,
            ])->assertCreated();
        }
    }

    public function test_legacy_fine_collection_expires_stale_online_attempt_without_a_read_request(): void
    {
        [$reader, $staff, $fine] = $this->fineFixture('1900.00');
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
        ])->assertOk()->json('quote');
        $payment = $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-stale-before-manual',
        ])->assertCreated()->json('payment');
        PaymentQuote::query()->whereKey($quote['id'])->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($staff)->postJson('/staff/fines/'.$fine->id.'/payments', [
            'amount' => '1900.00',
            'method' => 'manual',
        ])->assertCreated();
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => 'expired']);
        $this->assertDatabaseHas('fines', ['id' => $fine->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_events', ['payment_id' => $payment['id'], 'event_type' => 'quote_expired']);
        $this->assertSame(1, FinePayment::query()->where('fine_id', $fine->id)->count());
    }

    public function test_pending_fine_ledger_blocks_legacy_staff_collection_and_paid_legacy_fine_cannot_be_quoted(): void
    {
        [$reader, $staff, $fine] = $this->fineFixture('1800.00');
        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $fine->id,
        ])->assertOk()->json('quote');
        $this->postJson('/my/payments', [
            'quote_id' => $quote['id'],
            'method' => 'cash',
            'idempotency_key' => 'fine-cash-pending',
        ])->assertCreated();

        $this->actingAs($staff)->postJson('/staff/fines/'.$fine->id.'/payments', [
            'amount' => '1800.00',
            'method' => 'manual',
        ])->assertStatus(409);
        $this->assertSame(0, FinePayment::query()->where('fine_id', $fine->id)->count());
        $this->assertDatabaseHas('fines', ['id' => $fine->id, 'status' => 'unpaid']);

        [$legacyReader, $legacyStaff, $legacyFine] = $this->fineFixture('900.00');
        $legacyFine->update(['status' => 'paid', 'paid_by' => $legacyStaff->id, 'paid_at' => now()]);
        $legacyFine->payments()->create([
            'received_by' => $legacyStaff->id,
            'amount' => '900.00',
            'method' => 'manual',
            'paid_at' => now(),
        ]);
        $this->actingAs($legacyReader)->postJson('/my/payment-quotes', [
            'purpose' => 'fine',
            'reference_id' => (string) $legacyFine->id,
        ])->assertStatus(409);
    }

    private function fineFixture(string $amount): array
    {
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book();
        $loan = Loan::query()->create([
            'reader_id' => $reader->id,
            'issued_by' => $staff->id,
            'issued_at' => now()->subDays(20),
            'due_at' => now()->subDays(10),
            'status' => 'returned',
        ]);
        $loanItem = LoanItem::query()->create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'returned_quantity' => 1,
            'rental_fee_per_copy' => '0.00',
        ]);
        $loanReturn = LoanReturn::query()->create([
            'loan_id' => $loan->id,
            'returned_by' => $staff->id,
            'returned_at' => now(),
        ]);
        $returnItem = LoanReturnItem::query()->create([
            'loan_return_id' => $loanReturn->id,
            'loan_item_id' => $loanItem->id,
            'quantity' => 1,
        ]);
        $fine = Fine::query()->create([
            'loan_id' => $loan->id,
            'loan_return_item_id' => $returnItem->id,
            'reader_id' => $reader->id,
            'overdue_days' => 10,
            'quantity' => 1,
            'daily_rate' => 100,
            'amount' => $amount,
            'status' => 'unpaid',
        ]);

        return [$reader, $staff, $fine];
    }

    private function fineFixtureForReader(User $reader, string $amount): Fine
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $book = $this->book();
        $loan = Loan::query()->create([
            'reader_id' => $reader->id,
            'issued_by' => $staff->id,
            'issued_at' => now()->subDays(20),
            'due_at' => now()->subDays(10),
            'status' => 'returned',
        ]);
        $loanItem = LoanItem::query()->create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'returned_quantity' => 1,
            'rental_fee_per_copy' => '0.00',
        ]);
        $loanReturn = LoanReturn::query()->create([
            'loan_id' => $loan->id,
            'returned_by' => $staff->id,
            'returned_at' => now(),
        ]);
        $returnItem = LoanReturnItem::query()->create([
            'loan_return_id' => $loanReturn->id,
            'loan_item_id' => $loanItem->id,
            'quantity' => 1,
        ]);

        return Fine::query()->create([
            'loan_id' => $loan->id,
            'loan_return_item_id' => $returnItem->id,
            'reader_id' => $reader->id,
            'overdue_days' => 10,
            'quantity' => 1,
            'daily_rate' => 100,
            'amount' => $amount,
            'status' => 'unpaid',
        ]);
    }

    private function book(): Book
    {
        return Book::query()->create([
            'book_code' => 'BK'.fake()->unique()->numerify('######'),
            'title' => 'Reader payment test book',
            'total_quantity' => 2,
            'rental_fee' => 0,
        ]);
    }

    private function attachPaidReservationSnapshot(Reservation $reservation, User $reader, User $staff, Book $book): void
    {
        $quote = PaymentQuote::query()->create([
            'id' => (string) Str::ulid(),
            'reader_id' => $reader->id,
            'purpose' => 'reservation',
            'reservation_id' => $reservation->id,
            'total_amount' => '1200.00',
            'currency' => 'VND',
            'items_snapshot' => [['book_id' => $book->id, 'title' => $book->title, 'quantity' => 1, 'unit_amount' => '1200.00']],
            'allowed_methods' => ['cash'],
            'expires_at' => now()->addDay(),
        ]);
        $payment = Payment::query()->create([
            'id' => (string) Str::ulid(),
            'reader_id' => $reader->id,
            'quote_id' => $quote->id,
            'reservation_id' => $reservation->id,
            'purpose' => 'reservation',
            'method' => 'cash',
            'status' => 'paid',
            'amount' => '1200.00',
            'currency' => 'VND',
            'idempotency_key' => 'reader-detail-'.Str::random(10),
            'request_fingerprint' => hash('sha256', Str::random(16)),
            'confirmed_at' => now(),
            'confirmed_by' => $staff->id,
        ]);
        $loan = Loan::query()->create([
            'reader_id' => $reader->id,
            'issued_by' => $staff->id,
            'issued_at' => now(),
            'due_at' => now()->addDays(14),
            'status' => 'active',
            'reservation_id' => $reservation->id,
            'payment_id' => $payment->id,
            'rental_fee_total' => '1200.00',
            'rental_fee_received' => '1200.00',
            'rental_fee_received_at' => now(),
            'rental_fee_received_by' => $staff->id,
            'rental_fee_payment_method' => 'cash',
        ]);
        LoanItem::query()->create([
            'loan_id' => $loan->id,
            'book_id' => $book->id,
            'quantity' => 1,
            'returned_quantity' => 0,
            'rental_fee_per_copy' => '1200.00',
        ]);
    }
}
