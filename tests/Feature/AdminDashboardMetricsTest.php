<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_circulation_counts_copies_by_vietnam_day_and_uses_unsettled_deposits(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 03:00:00', 'UTC'));
        $admin = User::factory()->create(['role' => 'Admin']);
        $librarian = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create();
        $book = Book::create(['book_code' => 'DASH01', 'title' => 'Sách kiểm tra báo cáo', 'total_quantity' => 20]);
        $issue = function (string $date, int $quantity) use ($librarian, $reader, $book): Loan {
            $loan = Loan::create([
                'reader_id' => $reader->id, 'issued_by' => $librarian->id,
                'issued_at' => $date, 'due_at' => Carbon::parse($date, 'UTC')->addDays(14),
            ]);
            $loan->items()->create(['book_id' => $book->id, 'quantity' => $quantity, 'rental_fee_per_copy' => 5000]);

            return $loan;
        };

        $beforeWeek = $issue('2026-11-09 16:59:59', 5);
        $firstDay = $issue('2026-11-09 17:00:00', 3);
        $firstDay->update([
            'rental_fee_received' => '12345.67', 'rental_fee_received_at' => $firstDay->issued_at,
            'rental_fee_payment_method' => 'cash',
            'status' => 'returned', 'returned_at' => now(), 'reader_name_snapshot' => 'Độc giả kiểm thử cọc',
        ]);
        $lastDay = $issue('2026-11-15 17:00:00', 2);

        $returned = $lastDay->returns()->create(['returned_by' => $librarian->id, 'returned_at' => '2026-11-16 02:59:59']);
        $returned->items()->create(['loan_item_id' => $lastDay->items()->firstOrFail()->id, 'quantity' => 1]);
        $firstDay->deposit()->create(['amount' => 300000, 'method' => 'cash', 'received_by' => $librarian->id, 'received_at' => $firstDay->issued_at]);
        $settled = $beforeWeek->deposit()->create(['amount' => 500000, 'method' => 'cash', 'received_by' => $librarian->id, 'received_at' => $beforeWeek->issued_at]);
        $beforeWeek->update(['status' => 'returned', 'returned_at' => now()]);
        $settled->settlement()->create([
            'deposit_used' => 70000, 'refund_amount' => 430000, 'refund_method' => 'cash',
            'settled_by' => $librarian->id, 'settled_at' => now(),
        ]);

        $dashboard = $this->actingAs($admin)->getJson('/staff/dashboard')->assertOk()
            ->assertJsonPath('unsettled_deposits', 1)
            ->assertJsonCount(7, 'circulation_days')
            ->assertJsonPath('circulation_days.0', ['date' => '2026-11-10', 'issued' => 3, 'returned' => 0])
            ->assertJsonPath('circulation_days.1', ['date' => '2026-11-11', 'issued' => 0, 'returned' => 0])
            ->assertJsonPath('circulation_days.6', ['date' => '2026-11-16', 'issued' => 2, 'returned' => 1])
            ->assertJsonCount(7, 'finance_days')
            ->assertJsonPath('finance_days.0', ['date' => '2026-11-10', 'received' => '312345.67', 'refunded' => '0.00'])
            ->assertJsonPath('finance_days.6', ['date' => '2026-11-16', 'received' => '0.00', 'refunded' => '430000.00']);
        $this->assertEquals(300000, $dashboard->json('deposit_held_now'));

        $deposits = $this->getJson('/staff/reports?'.http_build_query(['type' => 'deposits', 'q' => 'kiểm thử cọc']))
            ->assertOk()->assertJsonPath('rows.total', 1)->assertJsonPath('rows.data.0.reference', $firstDay->id)
            ->assertJsonPath('rows.data.0.reader_name', 'Độc giả kiểm thử cọc');
        $this->assertEquals(300000, $deposits->json('rows.data.0.amount'));
        $this->assertEquals(0, $deposits->json('rows.data.0.unpaid_fines'));
        $csv = $this->get('/staff/reports/export?type=deposits')->assertOk()->streamedContent();
        $this->assertStringContainsString('Độc giả kiểm thử cọc', $csv);

        $this->actingAs($librarian)->getJson('/staff/dashboard')->assertOk()
            ->assertJsonMissingPath('circulation_days')
            ->assertJsonMissingPath('finance_days')
            ->assertJsonMissingPath('deposit_held_now');
        $this->getJson('/staff/reports?type=deposits')->assertForbidden();
        $this->get('/staff/reports/export?type=deposits')->assertForbidden();
    }

    public function test_admin_filters_accounts_by_status_and_staff_role(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $librarian = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $locked = User::factory()->create(['role' => 'Độc giả', 'is_locked' => true]);

        $this->actingAs($admin)->getJson('/staff/accounts?'.http_build_query(['role' => 'Độc giả', 'status' => 'active']))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $reader->id);
        $this->getJson('/staff/accounts?status=locked')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $locked->id);
        $this->getJson('/staff/accounts?role=staff')
            ->assertOk()->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $librarian->id, 'role' => 'Thủ thư']);
        $this->actingAs($librarian)->getJson('/staff/accounts?role=staff')->assertForbidden();
    }
}
