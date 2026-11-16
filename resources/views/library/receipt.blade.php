<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · Thư viện Mây</title>
    <style>
        @page { size: A4; margin: 16mm; }
        * { box-sizing: border-box; }
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; padding: 24px; background: #edf2ee; color: #17342b; }
        main { max-width: 760px; margin: auto; background: white; padding: clamp(18px, 5vw, 48px); border-radius: 12px; }
        header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 2px solid #28574a; padding-bottom: 16px; }
        h1 { margin: 4px 0; font-size: 28px; }
        h2 { font-size: 18px; margin: 24px 0 8px; }
        button { background: #214f41; color: white; border: 0; padding: 10px 16px; border-radius: 8px; font: inherit; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 7px; border-bottom: 1px solid #a9bbae; text-align: left; vertical-align: top; }
        th { background: #edf2ee; }
        dl { display: grid; grid-template-columns: max-content 1fr; align-items: baseline; gap: 4px 16px; }
        dt, .brand { font-weight: 650; }
        dd { margin: 0; overflow-wrap: anywhere; }
        p { margin: 6px 0; overflow-wrap: anywhere; }
        @media (max-width: 480px) { body { padding: 8px; } dl { grid-template-columns: 1fr; } dd { margin-bottom: 6px; } }
        @media print { body, main { background: white; padding: 0; border-radius: 0; color: #111; } button { display: none; } tr, dl { break-inside: avoid; } }
    </style>
</head>
<body>
<main>
    @php
        $money = fn ($amount) => number_format((float) $amount, str_ends_with((string) $amount, '.00') || (float) $amount === (float) (int) $amount ? 0 : 2, ',', '.') . ' ₫';
        $date = fn ($time) => $time ? \Illuminate\Support\Carbon::parse($time)->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : '—';
        $method = fn ($value) => ['cash' => 'Tiền mặt', 'bank_transfer' => 'Chuyển khoản demo', 'manual' => 'Xử lý thủ công'][$value] ?? '—';
        $fineStatus = fn ($value) => ['paid' => 'Đã thu', 'unpaid' => 'Chưa thu', 'waived' => 'Đã miễn'][$value] ?? '—';
    @endphp
    <header>
        <div>
            <p class="brand">Thư viện Mây</p>
            <h1>{{ $title }}</h1>
        </div>
        <button type="button" onclick="window.print()">In / Lưu PDF</button>
    </header>

    @isset($loan)
        <dl>
            <dt>Mã phiếu</dt><dd>#{{ $loan->id }}</dd>
            <dt>Độc giả</dt><dd>{{ $loan->reader_name_snapshot ?? $loan->reader?->name }}</dd>
            <dt>Người phát</dt><dd>{{ $loan->issuer_name_snapshot ?? $loan->issuer?->name }}</dd>
            <dt>Ngày nhận</dt><dd>{{ $date($loan->issued_at) }}</dd>
            <dt>Hạn trả</dt><dd>{{ $date($loan->due_at) }}</dd>
        </dl>
        <h2>Sách giấy</h2>
        <table>
            <thead><tr><th scope="col">Sách</th><th scope="col">Số bản</th><th scope="col">Phí mỗi bản</th><th scope="col">Đã trả / Mất</th></tr></thead>
            <tbody>
            @foreach($loan->items as $item)
                <tr>
                    <td>{{ $item->book_code_snapshot ?? $item->book?->book_code }} · {{ $item->book_title_snapshot ?? $item->book?->title }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $money($item->rental_fee_per_copy) }}</td>
                    <td>{{ $item->returned_quantity }} / {{ $item->lost_quantity }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <h2>Biên nhận phí mượn</h2>
        <p>Đã thu {{ $money($loan->rental_fee_received) }} · {{ $method($loan->rental_fee_payment_method) }} · {{ $date($loan->rental_fee_received_at) }}</p>
        @if($loan->rental_fee_receipt_note)<p>{{ $loan->rental_fee_receipt_note }}</p>@endif

        @if($loan->deposit)
            <h2>Biên nhận cọc</h2>
            <p>Đã thu {{ $money($loan->deposit->amount) }} · {{ $method($loan->deposit->method) }} · {{ $date($loan->deposit->received_at) }}</p>
            <p>Người thu {{ $loan->deposit->receivedBy?->name ?? '—' }}</p>
            <p>Cọc mất sách {{ $money($loan->deposit->loss_protection_amount) }} · Cọc giữ sách {{ $money($loan->deposit->book_holding_amount) }}</p>
            @if($loan->deposit->receipt_note)<p>{{ $loan->deposit->receipt_note }}</p>@endif
            @if($settlement = $loan->deposit->settlement)
                <h2>Đối soát cọc #{{ $settlement->id }}</h2>
                <dl>
                    <dt>Thời điểm</dt><dd>{{ $date($settlement->settled_at) }}</dd>
                    <dt>Nhân viên</dt><dd>{{ $settlement->settledBy?->name }}</dd>
                    <dt>Khấu trừ</dt><dd>{{ $money($settlement->deposit_used) }}</dd>
                    <dt>Hoàn cọc</dt><dd>{{ $money($settlement->refund_amount) }} @if($settlement->refund_method) · {{ $method($settlement->refund_method) }} @endif</dd>
                    <dt>Thu thêm</dt><dd>{{ $money($settlement->additional_received) }} @if($settlement->additional_method) · {{ $method($settlement->additional_method) }} @endif</dd>
                </dl>
                @if($settlement->receipt_note)<p>{{ $settlement->receipt_note }}</p>@endif
            @endif
        @endif

        @if($loan->fines->isNotEmpty())
            <h2>Khoản phạt</h2>
            @foreach($loan->fines as $row)
                <p>{{ $row->fine_code }} · {{ $row->reason }} · {{ $money($row->amount) }} · {{ $fineStatus($row->status) }}</p>
            @endforeach
        @endif
    @endisset

    @isset($fine)
        @php($item = $fine->loanItem ?? $fine->returnItem?->loanItem)
        <dl>
            <dt>Mã phiếu</dt><dd>{{ $fine->fine_code ?? '#'.$fine->id }}</dd>
            <dt>Phiếu mượn</dt><dd>#{{ $fine->loan_id }}</dd>
            <dt>Độc giả</dt><dd>{{ $fine->loan?->reader_name_snapshot ?? $fine->reader?->name }}</dd>
            <dt>Sách</dt><dd>{{ $item?->book_title_snapshot ?? $item?->book?->title ?? '—' }} · {{ $fine->quantity }} bản</dd>
            <dt>Ngày lập</dt><dd>{{ $date($fine->created_at) }}</dd>
            <dt>Người lập</dt><dd>{{ $fine->createdBy?->name ?? '—' }}</dd>
            <dt>Lý do</dt><dd>{{ $fine->reason }}</dd>
            <dt>Số tiền</dt><dd>{{ $money($fine->amount) }}</dd>
            <dt>Trạng thái</dt><dd>{{ $fineStatus($fine->status) }}</dd>
        </dl>
        @if($fine->waiver_reason)
            <p>Được miễn bởi {{ $fine->waivedBy?->name }} lúc {{ $date($fine->waived_at) }}. {{ $fine->waiver_reason }}</p>
        @endif
        @foreach($fine->payments as $row)
            <h2>Biên nhận thu #{{ $row->id }}</h2>
            <p>{{ $money($row->amount) }} · {{ $method($row->method) }} · {{ $date($row->paid_at) }} · {{ $row->receivedBy?->name }}</p>
            @if($row->receipt_note)<p>{{ $row->receipt_note }}</p>@endif
        @endforeach
    @endisset

    @isset($payment)
        <dl>
            <dt>Mã giao dịch</dt><dd>{{ $payment->id }}</dd>
            <dt>Độc giả</dt><dd>{{ $payment->reader?->name }}</dd>
            <dt>Người thu</dt><dd>{{ $payment->confirmedBy?->name ?? '—' }}</dd>
            <dt>Thời điểm</dt><dd>{{ $date($payment->confirmed_at) }}</dd>
            <dt>Số tiền</dt><dd>{{ $money($payment->amount) }}</dd>
            <dt>Phương thức</dt><dd>{{ $method($payment->method) }}</dd>
            <dt>Trạng thái</dt><dd>{{ $payment->status === 'refunded' ? 'Đã hoàn phí' : 'Đã thu' }}</dd>
        </dl>
        @foreach($payment->quote?->items_snapshot ?? [] as $item)
            <p>{{ $item['title'] ?? 'Khoản thu' }} · {{ $item['quantity'] ?? 1 }} · {{ $money($item['line_amount'] ?? $payment->amount) }}</p>
        @endforeach
        @if($payment->receipt_note)<p>{{ $payment->receipt_note }}</p>@endif
    @endisset
</main>
</body>
</html>
