<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Reservation;
use App\Services\LibraryNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentRefundController extends Controller
{
    public function store(Request $request, Payment $payment, LibraryNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:cash,bank_transfer,manual'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'receipt_reference' => ['required', 'string', 'max:150'],
        ]);
        abort_unless($payment->purpose === 'reservation' && $payment->reservation_id, 422,
            'Chỉ hoàn phí cho giao dịch đặt trước.');

        [$id, $created] = DB::transaction(function () use ($request, $payment, $data): array {
            $reservation = Reservation::query()->whereKey($payment->reservation_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $refund = DB::table('payment_refunds')->where('payment_id', $locked->id)->first();
            if ($refund) {
                abort_unless($refund->method === $data['method'] && $refund->reason === $data['reason']
                    && $refund->receipt_reference === $data['receipt_reference'], 409,
                    'Giao dịch này đã có biên nhận hoàn phí khác.');

                return [$refund->id, false];
            }

            $now = now();
            abort_unless($locked->status === 'paid' && $locked->confirmed_at && $reservation->payment_status === 'paid'
                && in_array($reservation->status, ['ready', 'expired'], true)
                && $reservation->expires_at && $reservation->expires_at->lessThanOrEqualTo($now)
                && ! Loan::query()->where('reservation_id', $reservation->id)->exists(), 409,
                'Chỉ hoàn giao dịch đã thu, đã hết hạn giữ sách và chưa giao sách.');

            $id = DB::table('payment_refunds')->insertGetId([
                'payment_id' => $locked->id,
                'refunded_by' => $request->user()->id,
                'amount' => $locked->amount,
                'method' => $data['method'],
                'reason' => $data['reason'],
                'receipt_reference' => $data['receipt_reference'],
                'refunded_at' => $now,
            ]);
            $locked->update(['status' => 'refunded']);
            $reservation->update(['status' => 'expired', 'payment_status' => 'refunded']);
            PaymentEvent::create([
                'payment_id' => $locked->id,
                'actor_id' => $request->user()->id,
                'from_status' => 'paid',
                'to_status' => 'refunded',
                'event_type' => 'staff_refunded',
                'note' => $data['reason'],
                'created_at' => $now,
            ]);

            return [$id, true];
        }, 3);

        if ($created) {
            $notifier->reader($payment->reader, 'Đã hoàn phí đặt sách',
                'Thư viện đã ghi nhận hoàn phí cho yêu cầu đặt sách #'.$payment->reservation_id.'.', 'reservations');
        }

        return response()->json([
            'message' => $created ? 'Đã ghi nhận hoàn phí đặt sách.' : 'Biên nhận hoàn phí đã được ghi nhận trước đó.',
            'refund' => DB::table('payment_refunds')->find($id),
        ], $created ? 201 : 200);
    }
}
