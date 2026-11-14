<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanDueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_staff_selects_due_date_and_reader_sees_it_with_correct_timezone(): void
    {
        Carbon::setTestNow('2026-10-21 06:00:00');
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'DUE001', 'title' => 'Custom due date', 'total_quantity' => 2, 'rental_fee' => 5000]);
        $payload = [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'due_at' => '2026-10-28T17:00:00+07:00',
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash'],
        ];
        $this->actingAs($reader)->postJson('/staff/loans', $payload)->assertForbidden();
        $this->actingAs($staff)->postJson('/staff/loans', $payload)->assertCreated();
        $loan = Loan::with('items')->firstOrFail();
        $this->assertSame('2026-10-28 10:00:00', $loan->due_at->format('Y-m-d H:i:s'));
        $this->actingAs($reader)->getJson('/my/loans')->assertOk()
            ->assertJsonPath('data.0.due_at', '2026-10-28T10:00:00.000000Z');

        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->actingAs($staff)->postJson('/staff/loans/'.$loan->id.'/returns', [
            'items' => [['loan_item_id' => $loan->items[0]->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('fines.0.overdue_days', 1);
    }

    public function test_all_issuance_routes_reject_past_or_invalid_dates_without_issuing_a_loan(): void
    {
        Carbon::setTestNow('2026-10-21 06:00:00');
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'DUE002', 'title' => 'Date validation', 'total_quantity' => 2, 'rental_fee' => 5000]);
        $reservation = Reservation::create(['reader_id' => $reader->id, 'book_id' => $book->id, 'quantity' => 1, 'status' => 'ready', 'expires_at' => now()->addHours(48)]);
        $this->actingAs($staff);
        foreach (['invalid-date', '2026-10-20T12:00:00Z', '2026-10-21T06:00:00Z'] as $dueAt) {
            foreach (['/staff/loans', '/staff/reservations/'.$reservation->id.'/fulfill', '/staff/reservations/'.$reservation->id.'/handover'] as $url) {
                $this->postJson($url, [
                    'reader_id' => $reader->id,
                    'items' => [['book_id' => $book->id, 'quantity' => 1]],
                    'due_at' => $dueAt,
                    'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
                    'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash'],
                ])->assertUnprocessable()->assertJsonValidationErrors('due_at');
            }
        }
        $this->assertDatabaseCount('loans', 0);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'ready']);
    }

    public function test_legacy_reservation_fulfillment_accepts_custom_due_date(): void
    {
        Carbon::setTestNow('2026-10-21 06:00:00');
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'DUE003', 'title' => 'Reservation due date', 'total_quantity' => 2, 'rental_fee' => 5000]);
        $reservation = Reservation::create(['reader_id' => $reader->id, 'book_id' => $book->id, 'quantity' => 1, 'status' => 'ready', 'expires_at' => now()->addHours(48)]);
        $this->actingAs($staff)->postJson('/staff/reservations/'.$reservation->id.'/fulfill', [
            'due_at' => '2026-11-21T06:00:00Z',
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash'],
        ])->assertCreated()->assertJsonPath('loan.due_at', '2026-11-21T06:00:00.000000Z');
    }
}
