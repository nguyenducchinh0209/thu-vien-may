<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookAvailability;
use App\Services\LibraryCalendar;
use App\Services\StaffReports;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffReportController extends Controller
{
    public function dashboard(Request $request, StaffReports $reports): JsonResponse
    {
        $now = now();
        $tomorrow = $now->copy()->setTimezone(LibraryCalendar::TIMEZONE)->startOfDay()->addDay()->utc();
        $active = Loan::query()->where('status', 'active');

        $dashboard = [
            'books' => Book::query()->count(),
            'active_readers' => User::query()->where('role', 'Độc giả')->where('is_locked', false)->count(),
            'active_loans' => (clone $active)->count(),
            'overdue_loans' => (clone $active)->where('due_at', '<', $now)->count(),
            'due_today' => (clone $active)->where('due_at', '>=', $now)->where('due_at', '<', $tomorrow)->count(),
            'pending_reservations' => Reservation::query()->where('status', 'pending')->count(),
            'pending_payments' => Payment::query()
                ->where(fn ($query) => $query->where('status', 'confirming')
                    ->orWhere(fn ($cash) => $cash->where('status', 'pending')->where('method', 'cash')))
                ->whereHas('quote', fn ($quote) => $quote->where('expires_at', '>', $now))->count(),
            'unsettled_deposits' => $reports->unsettledDeposits()->count(),
            'expired_paid_reservations' => $reports->refundQueue()->count(),
        ];

        if ($request->user()->role === 'Admin') {
            $dashboard['staff_accounts'] = User::query()->whereIn('role', ['Admin', 'Thủ thư'])->count();
            $dashboard['locked_accounts'] = User::query()->where('is_locked', true)->count();
            $dashboard['deposit_held_now'] = $reports->heldDeposits();
            $dashboard['circulation_days'] = $reports->circulationWeek($now);
            $dashboard['finance_days'] = $reports->financeWeek($now);
        }

        return response()->json($dashboard);
    }

    public function index(Request $request, StaffReports $reports, BookAvailability $availability): JsonResponse
    {
        [$type, $query, $columns, $dates] = $this->report($request, $reports, $availability);
        $totals = $type === 'finance'
            ? DB::query()->fromSub(clone $query, 'ledger')->selectRaw('event AS category, SUM(amount) AS amount')
                ->groupBy('event')->pluck('amount', 'category')
            : null;

        return response()->json([
            'type' => $type,
            'columns' => $columns,
            'period' => $dates,
            'totals' => $totals,
            'deposit_held_now' => $type === 'finance' ? $reports->heldDeposits() : null,
            'rows' => $query->paginate(20)->withQueryString(),
        ]);
    }

    public function export(Request $request, StaffReports $reports, BookAvailability $availability): StreamedResponse
    {
        [$type, $query, $columns] = $this->report($request, $reports, $availability);

        return response()->streamDownload(function () use ($query, $columns): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_values($columns), escape: '');

            foreach ($query->cursor() as $row) {
                $values = [];
                foreach (array_keys($columns) as $key) {
                    $value = (string) ($row->{$key} ?? '');
                    $values[] = preg_match('/^[\p{Z}\s\x00-\x1F]*[=+@-]|^[\t\r\n]/u', $value) ? "'".$value : $value;
                }
                fputcsv($output, $values, escape: '');
            }

            fclose($output);
        }, "thu-vien-may-{$type}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function report(Request $request, StaffReports $reports, BookAvailability $availability): array
    {
        $data = $request->validate([
            'type' => ['required', 'in:circulation,overdue,inventory,finance,refunds,deposits'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = $data['type'];
        abort_unless($type !== 'deposits' || $request->user()->role === 'Admin', 403);
        $today = now(LibraryCalendar::TIMEZONE)->startOfDay();
        $fromLocal = Carbon::parse($data['from'] ?? $today->copy()->subDays(29)->toDateString(), LibraryCalendar::TIMEZONE);
        $toLocal = Carbon::parse($data['to'] ?? $today->toDateString(), LibraryCalendar::TIMEZONE);
        if ($toLocal->isBefore($fromLocal)) {
            throw ValidationException::withMessages(['to' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi.']);
        }
        $period = ['from' => $fromLocal->toDateString(), 'to' => $toLocal->toDateString()];
        $from = $fromLocal->copy()->utc();
        $until = $toLocal->copy()->addDay()->utc();
        $timestamps = ['occurred_at' => 'Thời điểm (UTC)'];
        [$query, $columns] = match ($type) {
            'finance' => [$reports->finance($from, $until), [
                ...$timestamps, 'event' => 'Khoản mục', 'reference' => 'Mã biên nhận', 'related_record' => 'Hồ sơ liên quan',
                'amount' => 'Số tiền (đ)', 'method' => 'Phương thức',
            ]],
            'circulation' => [$reports->circulation($from, $until), [
                ...$timestamps, 'event' => 'Thao tác', 'reference' => 'Mã phiếu', 'book_title' => 'Sách', 'quantity' => 'Số bản',
            ]],
            'overdue' => [$reports->overdue(), [
                ...$timestamps, 'reference' => 'Mã phiếu', 'book_title' => 'Sách', 'quantity' => 'Còn phải trả',
            ]],
            'inventory' => [$reports->inventory($availability), [
                'reference' => 'Mã sách', 'book_title' => 'Tên sách', 'total' => 'Lưu hành', 'on_loan' => 'Đang mượn',
                'reserved' => 'Đang giữ', 'available' => 'Khả dụng', 'lost' => 'Mất (lũy kế)', 'written_off' => 'Thu hồi (lũy kế)',
            ]],
            'refunds' => [$reports->refundQueue(), [
                ...$timestamps, 'reference' => 'Giao dịch', 'related_record' => 'Yêu cầu đặt', 'book_title' => 'Sách', 'amount' => 'Số tiền (đ)',
            ]],
            'deposits' => [$reports->unsettledDeposits(), [
                'occurred_at' => 'Ngày kết thúc (UTC)', 'reference' => 'Mã phiếu mượn', 'reader_name' => 'Độc giả',
                'amount' => 'Cọc đã thu (đ)', 'unpaid_fines' => 'Phạt chưa thu (đ)',
            ]],
        };
        $search = trim($data['q'] ?? '');
        if ($search !== '') {
            $query = DB::query()->fromSub($query, 'report_rows')->where(function (Builder $filter) use ($search, $columns): void {
                $filter->where('reference', 'like', '%'.$search.'%');
                if (isset($columns['book_title'])) {
                    $filter->orWhere('book_title', 'like', '%'.$search.'%');
                }
                if (isset($columns['related_record'])) {
                    $filter->orWhere('related_record', 'like', '%'.$search.'%');
                }
                if (isset($columns['reader_name'])) {
                    $filter->orWhere('reader_name', 'like', '%'.$search.'%');
                }
            });
        }
        if (isset($columns['occurred_at'])) {
            $query->orderByDesc('occurred_at');
        }

        return [$type, $query->orderBy('reference')->orderBy('row_id'), $columns, $period];
    }
}
