<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Payment;
use App\Models\PaymentQuote;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettlementAndRefundReceiptRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_fine_receipt_shows_deposit_and_actual_additional_payment_without_overflowing_unicode_note(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'SETREC01', 'title' => 'Settlement receipt book', 'total_quantity' => 1, 'rental_fee' => 1000]);
        $loan = $this->actingAs($staff)->postJson('/staff/loans', [
            'reader_id' => $reader->id,
            'items' => [['book_id' => $book->id, 'quantity' => 1]],
            'rental_fee_payment' => ['amount' => '1000.00', 'method' => 'cash'],
            'deposit_payment' => ['amount' => '100000.00', 'method' => 'cash'],
        ])->assertCreated()->json('loan');
        $fine = $this->postJson('/staff/fines', [
            'loan_item_id' => $loan['items'][0]['id'], 'type' => 'other', 'quantity' => 1,
            'reason' => 'Phí phát sinh khi trả sách', 'amount' => '150000.00', 'request_key' => 'settlement-receipt-1',
        ])->assertCreated()->json('fine');
        $this->postJson("/staff/loans/{$loan['id']}/returns", [
            'items' => [['loan_item_id' => $loan['items'][0]['id'], 'quantity' => 1]],
        ])->assertOk();
        $this->assertSame('150000.00', $fine['amount']);

        $note = str_repeat('ộ', 500);
        $this->actingAs($staff)->postJson("/staff/loans/{$loan['id']}/deposit/settle", [
            'additional_method' => 'bank_transfer', 'receipt_note' => $note,
        ])->assertOk();

        $this->assertDatabaseHas('loan_deposit_settlements', ['loan_deposit_id' => $loan['deposit']['id'], 'receipt_note' => $note]);
        $paymentNote = DB::table('fine_payments')->where('fine_id', $fine['id'])->value('receipt_note');
        $this->assertLessThanOrEqual(500, mb_strlen($paymentNote));

        $receipt = $this->actingAs($staff)->get("/staff/receipts/fines/{$fine['id']}")->assertOk();
        $receipt->assertSee('Đã trừ từ tiền cọc 100.000 ₫')
            ->assertSee('Tiền thu thêm thực tế 50.000 ₫')
            ->assertSee('Chuyển khoản demo')
            ->assertDontSee('70.000 ₫ · Chuyển khoản demo');

        $finePaymentId = DB::table('fine_payments')->where('fine_id', $fine['id'])->value('id');
        $path = "deposit.settlement.allocations.0";
        $this->actingAs($staff)->getJson("/staff/loans/{$loan['id']}")->assertOk()
            ->assertJsonPath("loan.{$path}.fine_id", $fine['id'])
            ->assertJsonPath("loan.{$path}.deposit_amount", '100000.00')
            ->assertJsonPath("loan.{$path}.additional_amount", '50000.00')
            ->assertJsonPath("loan.{$path}.fine_payment_id", $finePaymentId);
        $this->actingAs($reader)->getJson("/my/loans/{$loan['id']}")->assertOk()
            ->assertJsonPath("loan.{$path}.fine_id", $fine['id'])
            ->assertJsonPath("loan.{$path}.deposit_amount", '100000.00')
            ->assertJsonPath("loan.{$path}.additional_amount", '50000.00')
            ->assertJsonPath("loan.{$path}.fine_payment_id", $finePaymentId);
    }

    public function test_refunded_payment_receipt_keeps_original_collection_and_prints_refund_details(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $book = Book::create(['book_code' => 'REFREC01', 'title' => 'Refund receipt book', 'total_quantity' => 1, 'rental_fee' => 5000]);
        $reservation = Reservation::create([
            'reader_id' => $reader->id, 'book_id' => $book->id, 'quantity' => 1,
            'status' => 'expired', 'payment_status' => 'refunded', 'payment_method' => 'cash',
            'amount' => '5000.00', 'currency' => 'VND', 'expires_at' => now()->subMinute(),
        ]);
        $quoteId = (string) Str::ulid();
        PaymentQuote::create([
            'id' => $quoteId, 'reader_id' => $reader->id, 'purpose' => 'reservation',
            'reservation_id' => $reservation->id, 'total_amount' => '5000.00', 'currency' => 'VND',
            'items_snapshot' => [['title' => 'Refund receipt book', 'quantity' => 1, 'line_amount' => '5000.00']],
            'allowed_methods' => ['cash'], 'expires_at' => now()->addDay(),
        ]);
        $paymentId = (string) Str::ulid();
        Payment::create([
            'id' => $paymentId, 'reader_id' => $reader->id, 'quote_id' => $quoteId,
            'reservation_id' => $reservation->id, 'purpose' => 'reservation', 'method' => 'cash',
            'status' => 'refunded', 'amount' => '5000.00', 'currency' => 'VND',
            'idempotency_key' => 'refund-receipt-regression', 'request_fingerprint' => str_repeat('a', 64),
            'confirmed_at' => now()->subDay(), 'confirmed_by' => $staff->id, 'receipt_note' => 'Original collection record',
        ]);
        DB::table('payment_refunds')->insert([
            'payment_id' => $paymentId, 'refunded_by' => $staff->id, 'amount' => '5000.00',
            'method' => 'bank_transfer', 'reason' => 'Reservation expired before handover',
            'receipt_reference' => 'RF-2026-001', 'refunded_at' => now(),
        ]);

        $this->actingAs($staff)->get("/staff/receipts/payments/{$paymentId}")->assertOk()
            ->assertSee('Original collection record')
            ->assertSee('RF-2026-001')
            ->assertSee('Reservation expired before handover')
            ->assertSee('Thời điểm hoàn')
            ->assertSee($staff->name)
            ->assertSee('Chuyển khoản demo')
            ->assertSee('Số tiền hoàn');
    }
}
