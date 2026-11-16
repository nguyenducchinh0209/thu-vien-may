<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StaffReports
{
    public function finance(CarbonInterface $from, CarbonInterface $until): Builder
    {
        $rental = DB::table('loans as l')
            ->whereNull('l.payment_id')->whereNotNull('l.rental_fee_received_at')
            ->selectRaw('l.id AS row_id, l.rental_fee_received_at AS occurred_at, ? AS event, l.id AS reference,
                l.id AS related_record, l.rental_fee_received AS amount, l.rental_fee_payment_method AS method', ['Phí mượn tại quầy']);

        $payments = DB::table('payments as p')
            ->whereIn('p.purpose', ['vip', 'reservation'])->whereIn('p.status', ['paid', 'refunded'])
            ->selectRaw('p.id AS row_id, p.confirmed_at AS occurred_at,
                CASE WHEN p.purpose = ? THEN ? ELSE ? END AS event, p.id AS reference,
                p.reservation_id AS related_record, p.amount, p.method', ['vip', 'Phí VIP', 'Phí đặt trước']);

        $fines = DB::table('fine_payments as f')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_deposit_fine_allocations as a')
                ->whereColumn('a.fine_id', 'f.fine_id'))
            ->selectRaw('f.id AS row_id, f.paid_at AS occurred_at, ? AS event, f.id AS reference,
                f.fine_id AS related_record, f.amount, f.method', ['Thu phạt riêng']);

        $deposits = DB::table('loan_deposits as d')
            ->selectRaw('d.id AS row_id, d.received_at AS occurred_at, ? AS event, d.id AS reference,
                d.loan_id AS related_record, d.amount, d.method', ['Thu cọc']);

        $settlements = DB::table('loan_deposit_settlements as s')
            ->join('loan_deposits as d', 'd.id', '=', 's.loan_deposit_id');
        $used = (clone $settlements)->where('s.deposit_used', '>', 0)
            ->selectRaw('s.id AS row_id, s.settled_at AS occurred_at, ? AS event, s.id AS reference,
                d.loan_id AS related_record, s.deposit_used AS amount, ? AS method', ['Phạt khấu trừ vào cọc', 'deposit']);
        $returned = (clone $settlements)->where('s.refund_amount', '>', 0)
            ->selectRaw('s.id AS row_id, s.settled_at AS occurred_at, ? AS event, s.id AS reference,
                d.loan_id AS related_record, s.refund_amount AS amount, s.refund_method AS method', ['Hoàn cọc']);
        $extra = (clone $settlements)->where('s.additional_received', '>', 0)
            ->selectRaw('s.id AS row_id, s.settled_at AS occurred_at, ? AS event, s.id AS reference,
                d.loan_id AS related_record, s.additional_received AS amount, s.additional_method AS method', ['Thu thêm tiền phạt']);

        $refunds = DB::table('payment_refunds as r')
            ->join('payments as p', 'p.id', '=', 'r.payment_id')
            ->selectRaw('r.id AS row_id, r.refunded_at AS occurred_at, ? AS event, r.receipt_reference AS reference,
                p.reservation_id AS related_record, r.amount, r.method', ['Hoàn phí đặt trước']);

        $rows = $rental->unionAll($payments)->unionAll($fines)->unionAll($deposits)
            ->unionAll($used)->unionAll($returned)->unionAll($extra)->unionAll($refunds);

        return DB::query()->fromSub($rows, 'finance_rows')->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until);
    }

    public function circulation(CarbonInterface $from, CarbonInterface $until): Builder
    {
        $issues = DB::table('loan_items as i')
            ->join('loans as l', 'l.id', '=', 'i.loan_id')
            ->join('books as b', 'b.id', '=', 'i.book_id')
            ->selectRaw('i.id AS row_id, l.issued_at AS occurred_at, ? AS event, l.id AS reference, COALESCE(i.book_title_snapshot, b.title) AS book_title, i.quantity',
                ['Phát phiếu']);

        $returns = DB::table('loan_return_items as i')
            ->join('loan_returns as r', 'r.id', '=', 'i.loan_return_id')
            ->join('loan_items as l', 'l.id', '=', 'i.loan_item_id')
            ->join('books as b', 'b.id', '=', 'l.book_id')
            ->selectRaw('i.id AS row_id, r.returned_at AS occurred_at, ? AS event, r.loan_id AS reference, COALESCE(l.book_title_snapshot, b.title) AS book_title, i.quantity',
                ['Nhận trả']);

        $losses = DB::table('fines as f')
            ->join('loan_items as l', 'l.id', '=', 'f.loan_item_id')
            ->join('books as b', 'b.id', '=', 'l.book_id')
            ->whereIn('f.type', ['lost', 'unusable'])
            ->selectRaw('f.id AS row_id, f.created_at AS occurred_at, CASE WHEN f.type = ? THEN ? ELSE ? END AS event,
                f.loan_id AS reference, COALESCE(l.book_title_snapshot, b.title) AS book_title, f.quantity', ['lost', 'Xác nhận mất', 'Thu hồi']);

        return DB::query()->fromSub($issues->unionAll($returns)->unionAll($losses), 'circulation_rows')
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until);
    }

    /** @return list<array{date: string, issued: int, returned: int}> */
    public function circulationWeek(CarbonInterface $now): array
    {
        return $this->weekTotals($now, $this->circulation(...), 'quantity', [
            'Phát phiếu' => 'issued',
            'Nhận trả' => 'returned',
        ]);
    }

    /** @return list<array{date: string, received: string, refunded: string}> */
    public function financeWeek(CarbonInterface $now): array
    {
        $days = $this->weekTotals($now, $this->finance(...), 'ROUND(amount * 100)', [
            'Phí mượn tại quầy' => 'received',
            'Phí VIP' => 'received',
            'Phí đặt trước' => 'received',
            'Thu phạt riêng' => 'received',
            'Thu cọc' => 'received',
            'Thu thêm tiền phạt' => 'received',
            'Hoàn cọc' => 'refunded',
            'Hoàn phí đặt trước' => 'refunded',
        ]);

        return array_map(fn (array $day) => [
            'date' => $day['date'],
            'received' => LibraryMoney::decimal($day['received']),
            'refunded' => LibraryMoney::decimal($day['refunded']),
        ], $days);
    }

    private function weekTotals(CarbonInterface $now, Closure $report, string $value, array $seriesByEvent): array
    {
        $today = $now->toImmutable()->setTimezone(LibraryCalendar::TIMEZONE)->startOfDay();
        $first = $today->subDays(6);
        $days = [];
        $buckets = [];
        $boundaries = [];

        for ($index = 0; $index < 7; $index++) {
            $day = $first->addDays($index);
            $days[] = ['date' => $day->toDateString(), ...array_fill_keys($seriesByEvent, 0)];
            $buckets[] = 'WHEN occurred_at < ? THEN '.$index;
            $boundaries[] = $day->addDay()->utc();
        }

        $totals = $report($first->utc(), $today->addDay()->utc())
            ->where('occurred_at', '<=', $now->toImmutable()->utc())
            ->whereIn('event', array_keys($seriesByEvent))
            ->selectRaw('CASE '.implode(' ', $buckets)." END AS day_index, event, SUM({$value}) AS total", $boundaries)
            ->groupBy('day_index', 'event')
            ->get();

        foreach ($totals as $total) {
            $days[(int) $total->day_index][$seriesByEvent[$total->event]] += (int) $total->total;
        }

        return $days;
    }

    public function overdue(): Builder
    {
        return DB::table('loan_items as i')
            ->join('loans as l', 'l.id', '=', 'i.loan_id')
            ->join('books as b', 'b.id', '=', 'i.book_id')
            ->where('l.status', 'active')->where('l.due_at', '<', now())
            ->whereRaw('i.quantity > i.returned_quantity + i.lost_quantity')
            ->selectRaw('i.id AS row_id, l.due_at AS occurred_at, l.id AS reference, COALESCE(i.book_title_snapshot, b.title) AS book_title,
                i.quantity - i.returned_quantity - i.lost_quantity AS quantity');
    }

    public function inventory(BookAvailability $availability): Builder
    {
        $movement = fn (string $column) => DB::table('loan_items')
            ->selectRaw("COALESCE(SUM({$column}), 0)")
            ->whereColumn('loan_items.book_id', 'books.id');

        $stock = DB::table('books')->whereNull('books.deleted_at')
            ->selectRaw('id AS row_id, book_code AS reference, title AS book_title, total_quantity AS total')
            ->selectSub($availability->onLoan(), 'on_loan')
            ->selectSub($availability->reserved(), 'reserved')
            ->selectSub($movement('lost_quantity'), 'lost')
            ->selectSub($movement('written_off_quantity'), 'written_off');

        return DB::query()->fromSub($stock, 'stock_rows')
            ->selectRaw('stock_rows.*, CASE WHEN total > on_loan + reserved THEN total - on_loan - reserved ELSE 0 END AS available');
    }

    public function refundQueue(): Builder
    {
        return DB::table('payments as p')->join('reservations as r', 'r.id', '=', 'p.reservation_id')
            ->join('books as b', 'b.id', '=', 'r.book_id')
            ->where('p.purpose', 'reservation')->where('p.status', 'paid')->whereNotNull('p.confirmed_at')->where('r.payment_status', 'paid')
            ->whereIn('r.status', ['ready', 'expired'])->where('r.expires_at', '<=', now())
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loans as l')
                ->whereColumn('l.reservation_id', 'r.id'))
            ->selectRaw('r.id AS row_id, r.expires_at AS occurred_at, p.id AS reference, r.id AS related_record,
                b.title AS book_title, p.amount, p.method');
    }

    public function heldDeposits(): string
    {
        return (string) $this->openDeposits()->sum('d.amount');
    }

    public function unsettledDeposits(): Builder
    {
        $unpaidFines = DB::table('fines as f')
            ->whereColumn('f.loan_id', 'l.id')->where('f.status', 'unpaid')
            ->selectRaw('COALESCE(SUM(f.amount), 0)');

        return $this->openDeposits()->join('loans as l', 'l.id', '=', 'd.loan_id')
            ->join('users as u', 'u.id', '=', 'l.reader_id')->where('l.status', 'returned')
            ->selectRaw('d.id AS row_id, l.returned_at AS occurred_at, l.id AS reference,
                COALESCE(l.reader_name_snapshot, u.name) AS reader_name, d.amount')
            ->selectSub($unpaidFines, 'unpaid_fines');
    }

    private function openDeposits(): Builder
    {
        return DB::table('loan_deposits as d')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('loan_deposit_settlements as s')
                ->whereColumn('s.loan_deposit_id', 'd.id'));
    }
}
