<?php

namespace Tests\Feature;

use App\Models\BookCart;
use App\Models\BookCartItem;
use App\Models\BookReview;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\ReaderProfile;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_data_is_repeatable_and_never_changes_account_or_reader_profile_rows(): void
    {
        $reader = User::factory()->create([
            'name' => 'Existing Reader',
            'email' => 'reader@example.test',
            'role' => 'Độc giả',
        ]);
        $reader->readerProfile()->update([
            'phone' => '0900000001',
            'address' => 'Existing address',
            'date_of_birth' => '1990-01-02',
        ]);
        $staff = User::factory()->create([
            'name' => 'Existing Staff',
            'email' => 'staff@example.test',
            'role' => 'Thủ thư',
        ]);
        $usersBefore = User::query()->orderBy('id')->get()->toArray();
        $profilesBefore = ReaderProfile::query()->orderBy('id')->get()->toArray();

        $this->seed(SampleDataSeeder::class);

        $this->assertDatabaseCount('authors', 5);
        $this->assertDatabaseCount('books', 15);
        $this->assertDatabaseCount('loans', 4);
        $this->assertDatabaseCount('loan_items', 5);
        $this->assertDatabaseCount('loan_returns', 2);
        $this->assertDatabaseCount('loan_return_items', 2);
        $this->assertDatabaseCount('fines', 2);
        $this->assertDatabaseCount('fine_payments', 1);
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseCount('book_reviews', 4);
        $this->assertDatabaseCount('book_comments', 5);
        $this->assertDatabaseCount('book_suggestions', 4);
        $this->assertDatabaseCount('book_carts', 1);
        $this->assertDatabaseCount('book_cart_items', 3);

        foreach (Loan::query()->with('items')->get() as $loan) {
            $expectedTotal = $loan->items->sum(fn (LoanItem $item): int => $item->quantity * (int) $item->rental_fee_per_copy);
            $this->assertEquals($expectedTotal, (float) $loan->rental_fee_total);
            $this->assertEquals($expectedTotal, (float) $loan->rental_fee_received);
        }

        $partialLoan = Loan::query()->where('rental_fee_receipt_note', 'SAMPLE-2026 loan PARTIAL')->firstOrFail();
        $partialItem = $partialLoan->items()->firstOrFail();
        $this->assertSame('active', $partialLoan->status);
        $this->assertSame(2, $partialItem->quantity);
        $this->assertSame(1, $partialItem->returned_quantity);

        $unpaidFine = Fine::query()->where('status', 'unpaid')->firstOrFail();
        $this->assertSame(5, $unpaidFine->overdue_days);
        $this->assertSame('15000.00', $unpaidFine->amount);

        $paidFine = Fine::query()->where('status', 'paid')->firstOrFail();
        $this->assertSame('18000.00', $paidFine->amount);
        $this->assertEquals(18000, (float) $paidFine->payments()->sum('amount'));

        $readyReservation = Reservation::query()->where('status', 'ready')->firstOrFail();
        $this->assertTrue($readyReservation->expires_at->isFuture());
        $this->assertTrue($readyReservation->expires_at->lessThanOrEqualTo(now()->addHours(48)));

        $cart = BookCart::query()->where('user_id', $reader->id)->firstOrFail();
        $this->assertSame(3, $cart->items()->count());
        $this->assertSame(2, BookCartItem::query()->where('book_cart_id', $cart->id)->max('quantity'));
        $this->assertSame(4, BookReview::query()->where('user_id', $reader->id)->distinct('book_id')->count('book_id'));

        $readerBeforeRerun = Reservation::query()->where('status', 'pending')->firstOrFail();
        $readerBeforeRerun->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $unpaidFine->update(['status' => 'paid', 'paid_by' => $staff->id, 'paid_at' => now()]);

        $this->seed(SampleDataSeeder::class);

        $this->assertDatabaseCount('books', 15);
        $this->assertDatabaseCount('loans', 4);
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseCount('fine_payments', 1);
        $this->assertDatabaseHas('reservations', ['id' => $readerBeforeRerun->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('fines', ['id' => $unpaidFine->id, 'status' => 'paid']);
        $this->assertSame($usersBefore, User::query()->orderBy('id')->get()->toArray());
        $this->assertSame($profilesBefore, ReaderProfile::query()->orderBy('id')->get()->toArray());
    }

    public function test_sample_data_requires_existing_reader_and_staff_without_creating_accounts(): void
    {
        User::factory()->create(['role' => 'Độc giả']);
        $usersBefore = User::query()->count();

        try {
            $this->seed(SampleDataSeeder::class);
            $this->fail('The seeder should require an existing staff account.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('existing active reader and staff account', $exception->getMessage());
        }

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertDatabaseCount('books', 0);
    }
}
