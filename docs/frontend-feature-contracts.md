# Frontend tài khoản, thanh toán, VIP và hỗ trợ

Backend đọc [BACKEND-HANDOFF.md](BACKEND-HANDOFF.md) để biết hợp đồng API và các quy tắc dữ liệu hiện hành. Các endpoint hồ sơ, VIP, thanh toán, đặt trước và đọc EPUB đã được triển khai trong nhánh tích hợp này.

Frontend được phục vụ trực tiếp từ `public/library`, không cần build Vite. API code cho giới tính, VIP, thanh toán và quy trình đặt trước đã được thêm, nhưng layout giữ các cờ này tắt cho tới khi các migration của nhánh được áp dụng. Chat luôn tắt trong phạm vi này. Chuyển khoản chỉ là mô phỏng, không có QR hoặc kết nối ngân hàng; nhân viên phải xác nhận thủ công trước khi khoản thu được ghi nhận.

## Cấu hình tính năng

Layout đặt cấu hình trước **các script defer**. Sau khi áp dụng migration và kiểm tra các endpoint trên database triển khai, bật các cờ đã hoàn tất; giữ chat tắt vì API chat chưa thuộc phạm vi tích hợp. Không đặt khóa AI/cổng thanh toán trong trình duyệt.

```js
window.libraryFeatureConfig = {
  capabilities: {
    membership: false,
    plans: false,
    payments: false,
    reservationWorkflow: false,
    chat: false,
    gender: false,
  },
  endpoints: {
    membership: '/my/membership',
    plans: '/membership/plans',
    quote: '/my/payment-quotes',
    payments: '/my/payments',
    reservations: '/my/reservations',
    cartReservations: '/my/cart/reservations',
    staffReservations: '/staff/reservations',
    chat: '/support/chat',
  },
};
```

Endpoint phải cùng origin; client gửi session cookie, Accept JSON và CSRF token cho POST. Lỗi HTTP trả `{ "message": "Thông báo tiếng Việt" }`; thời gian chờ 20 giây. Backend chịu trách nhiệm xác thực, phân quyền, quyền sở hữu, số tiền, hạn giữ sách và chống giao dịch lặp.

## Hợp đồng tối thiểu

### Quy trình đặt trước và xác nhận thanh toán

Bản tích hợp bật `reservationWorkflow` cùng `payments`. `GET /my/reservations` và `GET /staff/reservations` trả cùng các trường: `status` (`pending`, `ready`, `fulfilled`, `cancelled`, `expired`), `payment_status` (`unpaid`, `pending`, `confirming`, `paid`...), `payment_method` (`bank_transfer` hoặc `cash`), `amount` và `expires_at`. `ready` chỉ cho biết sách đang được giữ trong kho; trạng thái phía độc giả chỉ là **Đã giữ** khi API trả `payment_status: paid`. Thiếu trường thanh toán thì giao diện hiện “chưa rõ thanh toán”, không tự suy ra.

- Độc giả gửi giỏ sách bằng POST `/my/cart/reservations` với `{payment_method: 'bank_transfer'|'cash'}`. Phương thức này chỉ là lựa chọn dự kiến; yêu cầu tạo ra ở trạng thái chờ duyệt. Trả `{reservations: [...]}` để giao diện đồng bộ số yêu cầu vừa tạo.
- Thủ thư duyệt bằng POST `/staff/reservations/{id}/approve`. API giữ sách, giữ nguyên phương thức độc giả chọn và bắt đầu **một hạn 48 giờ** cho thanh toán và nhận sách. Lúc này payment status là `unpaid` và độc giả thấy **Chờ thanh toán**.
- Báo giá và tạo payment vẫn dùng hợp đồng bên dưới. Cash chỉ tạo khoản chờ thu. Với chuyển khoản mô phỏng, độc giả gọi POST `/my/payments/{id}/transfer`; API chuyển sang `confirming`. Nút chỉ gửi thông báo để nhân viên kiểm tra và xác nhận, không thực hiện hoặc chứng minh giao dịch ngân hàng thật.
- Thủ thư xác nhận bằng POST `/staff/reservations/{id}/confirm-payment` với `{method}`. Chỉ phản hồi API `payment_status: paid` mới chuyển độc giả sang **Đã giữ** và thủ thư sang **Thanh toán thành công**.
- Khi bàn giao sách tại quầy, thủ thư gọi POST `/staff/reservations/{id}/handover`. Thao tác này tạo phiếu mượn, không thu phí lần nữa; API trả trạng thái `fulfilled`. Ngày hạn mượn bắt đầu từ thời điểm bàn giao.

Các endpoint thao tác đều trả bản ghi đặt trước đã cập nhật; frontend tải lại danh sách sau khi hoàn tất. API phải kiểm tra quyền, phương thức đã chọn, khoản tiền theo báo giá, hạn 48 giờ, tính lặp an toàn và trạng thái hợp lệ. Khi capability tắt, các nút mới không hoạt động; các yêu cầu cũ thiếu payment status vẫn đọc được theo dữ liệu API hiện có.

### Hồ sơ và thành viên

- GET/PATCH `/my/profile` giữ nguyên `{user, profile}`. Khi `gender` bật, `profile.gender` nhận `null`, `male`, `female`, `other`, `prefer_not_to_say`. JSON PATCH gửi `null` cho chưa cập nhật; multipart gửi chuỗi rỗng, backend chuẩn hóa thành null. Các trường hiện có vẫn lưu qua API hiện tại khi gender tắt.
- GET `/my/membership`: `{membership: {tier: 'free'|'vip', status: 'active'|'expired'|'pending', expires_at: ISO8601|null}}`. VIP chỉ được hiển thị khi active và chưa hết hạn. Phản hồi thiếu không được suy ra là miễn phí.
- GET `/membership/plans`: `{plans: [{id: 'vip-month', name: 'VIP 1 tháng', price: 200000, duration_label: '1 tháng', benefits: ['Đọc trực tuyến toàn văn sách EPUB']}]}`. VIP có hiệu lực một tháng lịch sau khi nhân viên xác nhận đã thu tiền; gia hạn nối tiếp từ ngày hết hạn hiện tại khi gói còn hiệu lực.

### Thanh toán

- GET `/my/reservations` giữ `status`, `expires_at`, `quantity`, `payment_status` (`unpaid`/`paid`/`pending`/...) để frontend mở thanh toán mới hoặc tiếp tục xem giao dịch hiện có. Chỉ `ready`, chưa hết hạn và `unpaid` được mở giao dịch mới; giao dịch pending/confirming/paid có thể được mở lại mà không tạo khoản thu thứ hai.
- POST `/my/payment-quotes`, body `{purpose: 'reservation'|'vip', reference_id: '7'}`:

```json
{
  "quote": {
    "id": "q-123",
    "purpose": "reservation",
    "reference_id": "7",
    "currency": "VND",
    "total_amount": 10000,
    "expires_at": "2027-01-01T12:00:00+07:00",
    "methods": ["bank_transfer", "cash"],
    "items": [{"title": "Tên sách", "quantity": 2, "unit_amount": 5000, "line_amount": 10000}],
    "reservation": {"id": 7, "status": "ready", "expires_at": "2027-01-02T12:00:00+07:00", "payment_status": "unpaid"}
  }
}
```

`reservation` bắt buộc khi purpose là reservation. Với VIP, reference_id là ID gói, items mô tả gói; không có reservation. Giá/tổng tiền lấy nguyên từ báo giá máy chủ; frontend không tự suy ra ưu đãi VIP hoặc giá mua sách. Backend từ chối báo giá không thuộc người dùng, hết hạn hoặc đã thanh toán.

- POST `/my/payments`, body `{quote_id, method: 'bank_transfer'|'cash', idempotency_key}`. Backend phải dùng idempotency key để trả lại cùng giao dịch khi client thử lại sau lỗi mạng, kiểm tra điều kiện/số tiền lần nữa trước khi tạo.
- GET `/my/payments/{id}` để kiểm tra thủ công trạng thái; cả hai endpoint trả:

```json
{
  "payment": {
    "id": "p-123",
    "quote_id": "q-123",
    "method": "bank_transfer",
    "status": "pending",
    "transfer": {
      "simulated": true,
      "bank_name": "Mô phỏng – không chuyển tiền thật · Test Library Bank",
      "account_name": "Thư viện Mây (tài khoản mô phỏng)",
      "account_number": "DEMO-LIBRARY-0001",
      "content": "DEMO-p-123"
    }
  }
}
```

Trạng thái: `pending`, `confirming`, `paid`, `failed`, `cancelled`, `expired`. Trạng thái khác được hiển thị là chưa xác định và cho kiểm tra lại, tuyệt đối không suy thành thành công. Thông tin ngân hàng và mã giao dịch trên màn hình chỉ dùng để minh họa; không chuyển tiền thật. Cash và chuyển khoản mô phỏng đều chỉ hoàn tất khi nhân viên xác nhận. Thanh toán không tự giao sách; kích hoạt VIP thuộc backend, frontend tải lại membership khi nhận `paid`.

## Chat

POST `/support/chat`, body `{message, history: [{role: 'user'|'assistant', content}]}`; chỉ gửi tối đa 8 tin nhắn ngữ cảnh trước đó. Trả `{answer: 'Câu trả lời', books: [{id: 3, title: 'Tên sách'}]}`. Tin nhắn tối đa 2.000 ký tự ở client, backend cần tự giới hạn/xác thực tương ứng.

Chỉ hỗ trợ hướng dẫn và danh mục công khai. Backend quản lý khóa AI, giới hạn tần suất và chỉ dẫn hệ thống; không cho công cụ AI thực hiện thanh toán/đặt sách. Frontend hiển thị câu trả lời dạng văn bản đã escape, không thực thi HTML. Hội thoại chỉ nằm trong bộ nhớ tab, xóa khi đổi tài khoản hoặc đăng xuất; phản hồi đến muộn bị bỏ qua.

## Điều hướng và kiểm thử

Route mới: `#profile`, `#profile-edit`, `#vip`, `#checkout/reservation/{id}`, `#checkout/vip/{planId}`, `#help`. Checkout yêu cầu tài khoản độc giả; sau đăng nhập quay lại route đã yêu cầu. Router mới nối vào auth router hiện có, không thêm một lớp ghi đè render.

Chạy kiểm thử frontend (Node có sẵn, không cần npm install):

```powershell
node --test tests/frontend/account-features.test.cjs
```

Các phản hồi giả lập chỉ tồn tại trong tests. Kiểm tra thủ công thêm hover/click/bàn phím menu, hủy hồ sơ, Back/Forward, màn hình nhỏ, các API chưa bật và đăng xuất. Kiểm thử Laravel dùng SQLite memory theo phpunit.xml, không dùng database vận hành.

`tests/frontend/responsive-preview.html` nhúng ứng dụng thật trong iframe 390 × 844, không thêm dữ liệu giả. Để xem nhanh, chạy `php -S 127.0.0.1:8001 -t tests/frontend`, rồi mở `http://127.0.0.1:8001/responsive-preview.html` khi ứng dụng chính đang chạy ở cổng 8000. Dừng máy chủ kiểm tra sau khi dùng.
