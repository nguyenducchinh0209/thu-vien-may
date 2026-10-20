<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentQuote;
use App\Models\Reservation;
use App\Models\User;
use App\Services\LoanIssuanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'in:reservation,vip'],
            'reference_id' => ['required', 'string', 'max:40'],
        ]);

        [$quote, $payment] = DB::transaction(function () use ($request, $data): array {
            if ($data['purpose'] === 'reservation') {
                $reservation = Reservation::query()
                    ->where('reader_id', $request->user()->id)
                    ->whereKey($data['reference_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $existingPayment = Payment::query()->with('quote')
                    ->where('reservation_id', $reservation->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingPayment) {
                    if (in_array($existingPayment->status, ['pending', 'confirming', 'paid'], true)
                        && $existingPayment->quote->expires_at->isFuture()
                        && $reservation->status === 'ready'
                        && $reservation->expires_at?->isFuture()) {
                        return [$existingPayment->quote, $existingPayment];
                    }
                    abort(409, 'Giao dịch đặt trước đã hết hiệu lực hoặc không thể tạo giao dịch khác.');
                }

                if ($reservation->status !== 'ready' || $reservation->payment_status !== 'unpaid'
                    || ! $reservation->expires_at?->isFuture() || $reservation->amount === null) {
                    abort(409, 'Yêu cầu đặt trước chưa được duyệt, đã hết hạn hoặc chưa có báo giá hợp lệ.');
                }

                $book = $reservation->book()->firstOrFail();
                $amount = (string) $reservation->amount;
                $quantity = (int) $reservation->quantity;
                $item = [
                    'book_id' => $reservation->book_id,
                    'title' => $book->title,
                    'quantity' => $quantity,
                    'unit_amount' => $this->formatCents(intdiv($this->moneyCents($amount), max(1, $quantity))),
                    'line_amount' => $amount,
                ];
                $quote = PaymentQuote::create([
                    'id' => (string) Str::ulid(),
                    'reader_id' => $request->user()->id,
                    'purpose' => 'reservation',
                    'reservation_id' => $reservation->id,
                    'total_amount' => $amount,
                    'currency' => $reservation->currency ?: 'VND',
                    'items_snapshot' => [$item],
                    'allowed_methods' => ['cash', 'bank_transfer'],
                    'expires_at' => $reservation->expires_at,
                ]);

                return [$quote, null];
            }

            $plan = config('membership.vip_plan');
            if ($data['reference_id'] !== $plan['id']) {
                abort(404, 'Không tìm thấy gói thành viên.');
            }

            $existingPayment = Payment::query()->with('quote')
                ->where('reader_id', $request->user()->id)
                ->where('purpose', 'vip')
                ->whereIn('status', ['pending', 'confirming'])
                ->whereHas('quote', fn (Builder $query) => $query->where('plan_id', $plan['id']))
                ->latest('created_at')
                ->lockForUpdate()
                ->first();
            if ($existingPayment && $existingPayment->quote->expires_at->isFuture()) {
                return [$existingPayment->quote, $existingPayment];
            }
            if ($existingPayment) {
                $this->transition($existingPayment, 'expired', 'quote_expired', null, 'Báo giá VIP đã hết hạn.');
            }

            $quote = PaymentQuote::create([
                'id' => (string) Str::ulid(),
                'reader_id' => $request->user()->id,
                'purpose' => 'vip',
                'plan_id' => $plan['id'],
                'total_amount' => $plan['price'],
                'currency' => $plan['currency'],
                'items_snapshot' => [[
                    'title' => $plan['name'],
                    'quantity' => 1,
                    'unit_amount' => $plan['price'],
                    'line_amount' => $plan['price'],
                ]],
                'allowed_methods' => ['cash', 'bank_transfer'],
                'expires_at' => now()->addHours((int) config('membership.quote_ttl_hours', 48)),
            ]);

            return [$quote, null];
        }, 3);

        return response()->json([
            'quote' => $this->quotePayload($quote),
            'payment' => $payment ? $this->paymentPayload($payment) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'string', 'size:26'],
            'method' => ['required', 'string', 'in:cash,bank_transfer'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $fingerprint = hash('sha256', $data['quote_id']."\n".$data['method']);

        [$payment, $created] = DB::transaction(function () use ($request, $data, $fingerprint): array {
            $existingKey = Payment::query()
                ->where('reader_id', $request->user()->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();
            if ($existingKey) {
                if (! hash_equals($existingKey->request_fingerprint, $fingerprint)) {
                    abort(409, 'Khóa idempotency đã được dùng cho yêu cầu thanh toán khác.');
                }

                return [$existingKey, false];
            }

            $quote = PaymentQuote::query()->whereKey($data['quote_id'])->lockForUpdate()->firstOrFail();
            abort_unless($quote->reader_id === $request->user()->id, 404);
            if (! $quote->expires_at->isFuture()) {
                abort(409, 'Báo giá đã hết hạn. Vui lòng tạo báo giá mới.');
            }
            if (! in_array($data['method'], $quote->allowed_methods, true)) {
                throw ValidationException::withMessages(['method' => 'Phương thức thanh toán không có trong báo giá.']);
            }

            $quotePayment = Payment::query()->where('quote_id', $quote->id)->lockForUpdate()->first();
            if ($quotePayment) {
                if ($quotePayment->reader_id === $request->user()->id && $quotePayment->method === $data['method']) {
                    return [$quotePayment, false];
                }
                abort(409, 'Báo giá này đã được dùng cho một giao dịch khác.');
            }

            $reservation = null;
            if ($quote->purpose === 'reservation') {
                $reservation = Reservation::query()->whereKey($quote->reservation_id)->lockForUpdate()->firstOrFail();
                abort_unless($reservation->reader_id === $request->user()->id, 404);
                $existingReservationPayment = Payment::query()->where('reservation_id', $reservation->id)->lockForUpdate()->first();
                if ($existingReservationPayment) {
                    if ($existingReservationPayment->quote_id === $quote->id
                        && $existingReservationPayment->method === $data['method']) {
                        return [$existingReservationPayment, false];
                    }
                    abort(409, 'Yêu cầu đặt trước đã có một giao dịch.');
                }
                if ($reservation->status !== 'ready' || $reservation->payment_status !== 'unpaid'
                    || ! $reservation->expires_at?->isFuture()) {
                    abort(409, 'Yêu cầu đặt trước không còn đủ điều kiện thanh toán.');
                }
            }

            $paymentId = (string) Str::ulid();
            $transfer = $data['method'] === 'bank_transfer' ? [
                'simulated' => true,
                'bank_name' => 'Mô phỏng – không chuyển tiền thật · Test Library Bank',
                'account_name' => 'Thư viện Mây (tài khoản mô phỏng)',
                'account_number' => 'DEMO-LIBRARY-0001',
                'content' => 'DEMO-'.$paymentId,
            ] : null;
            $payment = Payment::create([
                'id' => $paymentId,
                'reader_id' => $request->user()->id,
                'quote_id' => $quote->id,
                'reservation_id' => $quote->reservation_id,
                'purpose' => $quote->purpose,
                'method' => $data['method'],
                'status' => 'pending',
                'amount' => $quote->total_amount,
                'currency' => $quote->currency,
                'idempotency_key' => $data['idempotency_key'],
                'request_fingerprint' => $fingerprint,
                'transfer_snapshot' => $transfer,
            ]);
            $payment->events()->create([
                'actor_id' => $request->user()->id,
                'from_status' => null,
                'to_status' => 'pending',
                'event_type' => 'created',
                'created_at' => now(),
            ]);

            if ($reservation) {
                $reservation->update([
                    'payment_status' => 'pending',
                    'payment_method' => $data['method'],
                ]);
            }

            return [$payment, true];
        }, 3);

        return response()->json([
            'message' => $created ? 'Đã tạo yêu cầu thanh toán; giao dịch chờ thư viện xác nhận.' : 'Đã khôi phục giao dịch hiện có.',
            'payment' => $this->paymentPayload($payment),
        ], $created ? 201 : 200);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->reader_id === $request->user()->id, 404);
        $this->expireIfNeeded($payment);

        return response()->json(['payment' => $this->paymentPayload($payment->fresh())]);
    }

    public function reportTransfer(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->reader_id === $request->user()->id, 404);
        abort_unless($payment->method === 'bank_transfer', 409, 'Giao dịch này không dùng chuyển khoản mô phỏng.');
        $payment = DB::transaction(function () use ($request, $payment): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'confirming') {
                return $payment;
            }
            if ($payment->status !== 'pending') {
                abort(409, 'Giao dịch không còn chờ báo chuyển khoản.');
            }
            $quote = PaymentQuote::query()->whereKey($payment->quote_id)->lockForUpdate()->firstOrFail();
            if (! $quote->expires_at->isFuture()) {
                abort(409, 'Báo giá đã hết hạn.');
            }
            if ($payment->reservation_id) {
                $reservation = Reservation::query()->whereKey($payment->reservation_id)->lockForUpdate()->firstOrFail();
                if ($reservation->status !== 'ready' || ! $reservation->expires_at?->isFuture()) {
                    abort(409, 'Thời hạn giữ sách đã hết. Vui lòng liên hệ thư viện để được hướng dẫn.');
                }
                $reservation->update(['payment_status' => 'confirming']);
            }
            $payment->update(['reported_at' => now()]);
            $this->transition($payment, 'confirming', 'transfer_reported', $request->user()->id, 'Người dùng đã báo chuyển khoản mô phỏng.');

            return $payment->fresh();
        }, 3);

        return response()->json([
            'message' => 'Đã gửi thông báo. Thư viện cần xác nhận giao dịch thủ công.',
            'payment' => $this->paymentPayload($payment),
            'reservation' => $payment->reservation_id ? $this->reservationPayload($payment->reservation) : null,
        ]);
    }

    public function reportReservationTransfer(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate(['payment_id' => ['required', 'string', 'size:26']]);
        $payment = Payment::query()->whereKey($data['payment_id'])
            ->where('reservation_id', $reservation->id)->firstOrFail();

        return $this->reportTransfer($request, $payment);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $this->expirePendingPayments();
        $payments = Payment::query()
            ->with(['reader' => fn ($query) => $query->withTrashed()->select('id', 'name', 'email'), 'quote'])
            ->whereIn('status', ['pending', 'confirming'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->latest('created_at')
            ->paginate(max(1, min($request->integer('per_page', 20), 50)))
            ->through(fn (Payment $payment) => [
                ...$this->paymentPayload($payment),
                'reader' => $payment->reader?->only(['id', 'name', 'email']) ?? [
                    'id' => $payment->reader_id,
                    'name' => 'Độc giả đã ngừng hoạt động',
                    'email' => '',
                ],
                'reference' => $payment->purpose === 'reservation'
                    ? 'Yêu cầu đặt sách #'.$payment->reservation_id
                    : $payment->quote->items_snapshot[0]['title'],
            ]);

        return response()->json($payments);
    }

    public function confirm(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'string', 'in:cash,bank_transfer']]);
        $payment = DB::transaction(function () use ($request, $payment, $data): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'paid') {
                return $payment;
            }
            if ($payment->method !== $data['method']) {
                throw ValidationException::withMessages(['method' => 'Phương thức xác nhận không khớp với giao dịch.']);
            }
            $expectedStatus = $payment->method === 'cash' ? 'pending' : 'confirming';
            if ($payment->status !== $expectedStatus) {
                abort(409, $payment->method === 'bank_transfer'
                    ? 'Độc giả chưa báo đã chuyển khoản.'
                    : 'Giao dịch tiền mặt không còn chờ thu.');
            }
            $quote = PaymentQuote::query()->whereKey($payment->quote_id)->lockForUpdate()->firstOrFail();
            if (! $quote->expires_at->isFuture()) {
                abort(409, 'Báo giá đã hết hạn; giao dịch chưa được xác nhận.');
            }

            $reservation = null;
            if ($payment->reservation_id) {
                $reservation = Reservation::query()->whereKey($payment->reservation_id)->lockForUpdate()->firstOrFail();
                if ($reservation->status !== 'ready' || ! $reservation->expires_at?->isFuture()) {
                    abort(409, 'Thời hạn giữ sách đã hết; giao dịch chưa được xác nhận.');
                }
            }

            $now = now();
            $payment->update([
                'confirmed_at' => $now,
                'confirmed_by' => $request->user()->id,
            ]);
            $this->transition($payment, 'paid', 'staff_confirmed', $request->user()->id, 'Thư viện xác nhận đã nhận tiền.');

            if ($reservation) {
                $reservation->update(['payment_status' => 'paid']);
            } else {
                $this->grantVipMembership($payment, $now);
            }

            return $payment->fresh();
        }, 3);

        return response()->json([
            'message' => 'Đã xác nhận thanh toán.',
            'payment' => $this->paymentPayload($payment),
            'reservation' => $payment->reservation_id ? $this->reservationPayload($payment->reservation) : null,
            'membership' => $payment->purpose === 'vip' ? $this->membershipPayload($payment->reader_id) : null,
        ]);
    }

    public function confirmReservation(Request $request, Reservation $reservation): JsonResponse
    {
        $payment = Payment::query()->where('reservation_id', $reservation->id)->firstOrFail();

        return $this->confirm($request, $payment);
    }

    public function handover(Request $request, Reservation $reservation, LoanIssuanceService $loans): JsonResponse
    {
        $payment = Payment::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $loan = $loans->issuePaidReservation($request->user(), $reservation->id, $payment);

        return response()->json([
            'message' => 'Đã xác nhận giao sách.',
            'reservation' => $this->reservationPayload($reservation->fresh()),
            'loan' => $loan->load(['reader:id,name,email', 'items.book:id,title,book_code']),
        ]);
    }

    private function quotePayload(PaymentQuote $quote): array
    {
        $payload = [
            'id' => $quote->id,
            'purpose' => $quote->purpose,
            'reference_id' => (string) ($quote->reservation_id ?? $quote->plan_id),
            'currency' => $quote->currency,
            'total_amount' => $quote->total_amount,
            'expires_at' => $quote->expires_at->toISOString(),
            'methods' => $quote->allowed_methods,
            'items' => $quote->items_snapshot,
        ];
        if ($quote->purpose === 'reservation') {
            $payload['reservation'] = $this->reservationPayload($quote->reservation);
        }

        return $payload;
    }

    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'quote_id' => $payment->quote_id,
            'purpose' => $payment->purpose,
            'method' => $payment->method,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'transfer' => $payment->transfer_snapshot,
            'reported_at' => $payment->reported_at?->toISOString(),
            'confirmed_at' => $payment->confirmed_at?->toISOString(),
        ];
    }

    private function reservationPayload(?Reservation $reservation): ?array
    {
        if (! $reservation) {
            return null;
        }

        return [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'payment_status' => $reservation->payment_status,
            'payment_method' => $reservation->payment_method,
            'amount' => $reservation->amount,
            'currency' => $reservation->currency,
            'expires_at' => $reservation->expires_at?->toISOString(),
        ];
    }

    private function transition(Payment $payment, string $status, string $event, ?int $actorId, ?string $note): void
    {
        $previous = $payment->status;
        if ($previous !== $status) {
            $payment->update(['status' => $status]);
        }
        PaymentEvent::create([
            'payment_id' => $payment->id,
            'actor_id' => $actorId,
            'from_status' => $previous,
            'to_status' => $status,
            'event_type' => $event,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function grantVipMembership(Payment $payment, $now): void
    {
        User::query()->whereKey($payment->reader_id)->lockForUpdate()->firstOrFail();
        $membership = Membership::query()->where('user_id', $payment->reader_id)->lockForUpdate()->first();
        $active = $membership && $membership->expires_at->isFuture();
        $base = $active ? $membership->expires_at->copy() : $now->copy();
        $startsAt = $active ? $membership->starts_at : $now;
        $values = [
            'tier' => 'vip',
            'status' => 'active',
            'starts_at' => $startsAt,
            'expires_at' => $base->addMonthNoOverflow(),
            'payment_id' => $payment->id,
        ];
        if ($membership) {
            $membership->update($values);
        } else {
            Membership::create(['user_id' => $payment->reader_id, ...$values]);
        }
    }

    private function membershipPayload(int $userId): array
    {
        $membership = Membership::query()->where('user_id', $userId)->first();
        if (! $membership) {
            return ['tier' => 'free', 'status' => 'active', 'expires_at' => null];
        }

        return [
            'tier' => $membership->tier,
            'status' => $membership->expires_at->isFuture() ? 'active' : 'expired',
            'expires_at' => $membership->expires_at->toISOString(),
        ];
    }

    private function expireIfNeeded(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['paid', 'expired'], true)) {
                return;
            }
            $quote = PaymentQuote::query()->whereKey($locked->quote_id)->firstOrFail();
            $reservationExpired = $locked->reservation_id
                && ! Reservation::query()->whereKey($locked->reservation_id)->where('expires_at', '>', now())->exists();
            if (! $quote->expires_at->isFuture() || $reservationExpired) {
                $this->transition($locked, 'expired', $reservationExpired ? 'reservation_expired' : 'quote_expired', null, null);
                if ($locked->reservation_id) {
                    Reservation::query()->whereKey($locked->reservation_id)
                        ->where('payment_status', '!=', 'paid')
                        ->update(['payment_status' => 'expired']);
                }
            }
        }, 3);
    }

    private function expirePendingPayments(): void
    {
        Payment::query()->whereIn('status', ['pending', 'confirming'])->orderBy('created_at')
            ->chunkById(100, function ($payments): void {
                foreach ($payments as $payment) {
                    $this->expireIfNeeded($payment);
                }
            });
    }

    private function moneyCents(string $value): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid stored monetary amount.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
