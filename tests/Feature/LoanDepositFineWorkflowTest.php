<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanDepositSettlement;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LoanDepositFineWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_loss_fine_reduces_inventory_and_deposit_settlement_is_idempotent(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $other = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'DEP001', 'title' => 'Deposit book', 'total_quantity' => 1, 'rental_fee' => 5000]);
        $loanResponse = $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'issuance_key' => 'issue-idem-DEP001',
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash', 'receipt_note' => 'Cọc quầy'],
        ])->assertCreated()->assertJsonPath('loan.deposit.amount', '100000.00');
        $loanId = $loanResponse->json('loan.id');
        $loanItemId = $loanResponse->json('loan.items.0.id');
        $this->assertDatabaseHas('loans', ['id' => $loanId, 'issuance_key' => 'issue-idem-DEP001']);
        $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id, 'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'issuance_key' => 'issue-idem-DEP001',
            'rental_fee_payment' => ['amount' => '5000.00', 'method' => 'cash'],
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash', 'receipt_note' => 'Cọc quầy'],
        ])->assertOk()->assertJsonPath('loan.id', $loanId);
        $this->assertSame(1, Loan::count());

        $this->freezeTime();
        Loan::query()->whereKey($loanId)->update(['due_at' => now()->subDays(27)]);
        $fineResponse = $this->postJson('/staff/fines', [
            'loan_item_id' => $loanItemId, 'type' => 'lost', 'quantity' => 1,
            'reason' => 'Độc giả xác nhận không thể thu hồi sách', 'amount' => '150000.00', 'request_key' => 'lost-DEP001-1',
        ])->assertCreated()->assertJsonPath('fine.type', 'lost')->assertJsonPath('fine.amount', '70000.00');
        $fineId = $fineResponse->json('fine.id');
        $this->assertDatabaseHas('books', ['id' => $book->id, 'total_quantity' => 0]);
        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => 'returned']);
        $this->actingAs($other)->getJson('/my/loans/'.$loanId)->assertNotFound();
        $this->actingAs($reader)->getJson('/my/loans/'.$loanId)->assertOk()->assertJsonPath('loan.deposit.amount', '100000.00');

        $this->actingAs($staff)->getJson("/staff/loans/{$loanId}/deposit/settlement")
            ->assertOk()->assertJsonPath('additional_due', '51000.00')->assertJsonPath('refund', '0.00');
        $this->postJson("/staff/loans/{$loanId}/deposit/settle", [
            'additional_method' => 'cash',
            'expected_additional_due' => '50000.00',
        ])->assertConflict();
        $payload = ['additional_method' => 'cash', 'receipt_note' => 'Đã thu tiền chênh lệch',
            'expected_deposit_deduction' => '100000.00', 'expected_refund' => '0.00', 'expected_additional_due' => '51000.00'];
        $this->postJson("/staff/loans/{$loanId}/deposit/settle", $payload)->assertOk()
            ->assertJsonPath('settlement.deposit_used', '100000.00')
            ->assertJsonPath('settlement.additional_received', '51000.00');
        $this->postJson("/staff/loans/{$loanId}/deposit/settle", $payload)->assertOk();
        $this->assertSame(1, LoanDepositSettlement::count());
        $this->assertDatabaseHas('fines', ['id' => $fineId, 'status' => 'paid']);
        $this->assertDatabaseHas('loan_deposit_fine_allocations', ['fine_id' => $fineId, 'deposit_amount' => '19000.00', 'additional_amount' => '51000.00']);
    }

    public function test_pending_fine_payment_must_be_cancelled_before_deposit_settlement(): void
    {
        $staff = User::factory()->create(['role' => 'Admin']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'DEP002', 'title' => 'Pending payment book', 'total_quantity' => 1, 'rental_fee' => 1000]);
        $loan = $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id, 'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'rental_fee_payment' => ['amount' => '1000.00', 'method' => 'cash'],
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash'],
        ])->assertCreated()->json('loan');
        $fine = $this->postJson('/staff/fines', [
            'loan_item_id' => $loan['items'][0]['id'], 'type' => 'other', 'quantity' => 1,
            'reason' => 'Lỗi ngoài danh sách', 'amount' => '20000.00', 'request_key' => 'other-DEP002-1',
        ])->assertCreated()->json('fine');
        $this->postJson("/staff/loans/{$loan['id']}/returns", ['items' => [['loan_item_id' => $loan['items'][0]['id'], 'quantity' => 1]]])->assertOk();

        $quote = $this->actingAs($reader)->postJson('/my/payment-quotes', ['purpose' => 'fine', 'reference_id' => (string) $fine['id']])->assertOk()->json('quote');
        $payment = $this->postJson('/my/payments', ['quote_id' => $quote['id'], 'method' => 'cash', 'idempotency_key' => 'pending-deposit-'.uniqid()])->assertCreated()->json('payment');
        $this->actingAs($staff)->postJson("/staff/loans/{$loan['id']}/deposit/settle", ['refund_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->postJson("/staff/payments/{$payment['id']}/cancel", ['reason' => 'Đối soát cọc trước'])->assertOk();
        $this->postJson("/staff/loans/{$loan['id']}/deposit/settle", ['refund_method' => 'cash'])->assertOk()
            ->assertJsonPath('settlement.refund_amount', '80000.00');
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('fines', ['id' => $fine['id'], 'status' => 'paid']);
    }

    public function test_reading_shelf_lists_only_owned_epub_history_and_clamps_expired_access(): void
    {
        Storage::fake('local');
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $other = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'READ001', 'title' => 'Reading history', 'total_quantity' => 1, 'rental_fee' => 0]);
        $path = 'book-epubs/'.$book->id.'/sample.epub';
        $book->forceFill(['epub_path' => $path])->save();
        Storage::disk('local')->put($path, 'private sample');
        ReadingProgress::create(['user_id' => $reader->id, 'book_id' => $book->id, 'last_chapter' => 12, 'last_read_at' => now()]);

        $this->actingAs($reader)->getJson('/my/reading-books')->assertOk()
            ->assertJsonPath('data.0.book_id', $book->id)
            ->assertJsonPath('data.0.last_chapter', 3)
            ->assertJsonPath('data.0.can_read_full', false);
        $this->actingAs($other)->getJson('/my/reading-books')->assertOk()->assertJsonCount(0, 'data');
    }
}
