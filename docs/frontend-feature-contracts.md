# Frontend tài khoản, thanh toán, VIP và hỗ trợ

Backend đọc [BACKEND-HANDOFF.md](BACKEND-HANDOFF.md) để biết nguyên nhân lỗi chỉ hiện “Đã giữ”, thiết kế bảng/cột, đối soát dữ liệu cũ và các API cần triển khai. Các API tùy chọn mô tả ở đây chưa tồn tại trong backend hiện tại.

Frontend được phục vụ trực tiếp từ `public/library`, không cần build Vite. Không có API thanh toán, VIP hoặc AI mới được triển khai trong thay đổi này. Tất cả khả năng tùy chọn mặc định tắt; không có dữ liệu mẫu hoặc giao dịch thành công giả trong giao diện chạy thật.

## Bật kết nối khi backend đã sẵn sàng

Đặt cấu hình sau **trước các script defer** trong layout hoặc từ cấu hình triển khai. Chỉ bật từng khả năng sau khi backend đáp ứng đúng hợp đồng tương ứng. Không đặt khóa AI/cổng thanh toán trong trình duyệt.

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

Bật `reservationWorkflow` cùng `payments` khi tất cả endpoint dưới đây đã hoạt động. `GET /my/reservations` và `GET /staff/reservations` trả cùng các trường: `status` (`pending`, `ready`, `fulfilled`, `cancelled`, `expired`), `payment_status` (`unpaid`, `confirming`, `paid`...), `payment_method` (`bank_transfer` hoặc `cash`), `amount` và `expires_at`. `ready` chỉ cho biết sách đang được giữ trong kho; trạng thái phía độc giả chỉ là **Đã giữ** khi API trả `payment_status: paid`. Thiếu trường thanh toán thì giao diện hiện “chưa rõ thanh toán”, không tự suy ra.

- Độc giả gửi giỏ sách bằng POST `/my/cart/reservations` với `{payment_method: 'bank_transfer'|'cash'}`. Phương thức này chỉ là lựa chọn dự kiến; yêu cầu tạo ra ở trạng thái chờ duyệt. Trả `{reservations: [...]}` để giao diện đồng bộ số yêu cầu vừa tạo.
- Thủ thư duyệt bằng POST `/staff/reservations/{id}/approve`. API giữ sách, giữ nguyên phương thức độc giả chọn và bắt đầu **một hạn 48 giờ** cho thanh toán và nhận sách. Lúc này payment status là `unpaid` và độc giả thấy **Chờ thanh toán**.
- Báo giá và tạo payment vẫn dùng hợp đồng bên dưới. Cash chỉ tạo khoản chờ thu. Với QR, sau khi chuyển tiền độc giả gọi POST `/my/reservations/{id}/transfer` với `{payment_id}`; API chuyển sang `confirming`. Nút “Tôi đã chuyển khoản” chỉ báo đã gửi tiền, chưa xác nhận đã thu.
- Thủ thư xác nhận bằng POST `/staff/reservations/{id}/confirm-payment` với `{method}`. Chỉ phản hồi API `payment_status: paid` mới chuyển độc giả sang **Đã giữ** và thủ thư sang **Thanh toán thành công**.
- Khi bàn giao sách tại quầy, thủ thư gọi POST `/staff/reservations/{id}/handover`. Thao tác này tạo phiếu mượn, không thu phí lần nữa; API trả trạng thái `fulfilled`. Ngày hạn mượn bắt đầu từ thời điểm bàn giao.

Các endpoint thao tác đều trả bản ghi đặt trước đã cập nhật; frontend tải lại danh sách sau khi hoàn tất. API phải kiểm tra quyền, phương thức đã chọn, khoản tiền theo báo giá, hạn 48 giờ, tính lặp an toàn và trạng thái hợp lệ. Khi capability tắt, các nút mới không hoạt động; các yêu cầu cũ thiếu payment status vẫn đọc được theo dữ liệu API hiện có.

### Hồ sơ và thành viên

- GET/PATCH `/my/profile` giữ nguyên `{user, profile}`. Khi `gender` bật, `profile.gender` nhận `null`, `male`, `female`, `other`, `prefer_not_to_say`. JSON PATCH gửi `null` cho chưa cập nhật; multipart gửi chuỗi rỗng, backend chuẩn hóa thành null. Các trường hiện có vẫn lưu qua API hiện tại khi gender tắt.
- GET `/my/membership`: `{membership: {tier: 'free'|'vip', status: 'active'|'expired'|'pending', expires_at: ISO8601|null}}`. VIP chỉ được hiển thị khi active và chưa hết hạn. Phản hồi thiếu không được suy ra là miễn phí.
- GET `/membership/plans`: `{plans: [{id: 'vip-month', name: 'Tên gói', price: 100000, duration_label: '1 tháng', benefits: ['Quyền lợi do thư viện quy định']}]}`. Giá ở đây chỉ là minh họa cấu trúc hợp đồng, không được nhúng thành dữ liệu chạy thật. ID dùng chữ/số, gạch ngang hoặc gạch dưới.

### Thanh toán

- GET `/my/reservations` cần giữ `status`, `expires_at`, `quantity`, và bổ sung `payment_status` (`unpaid`/`paid`/`pending`/...) để frontend quyết định hiện nút Thanh toán. Chỉ `ready`, chưa hết hạn và `unpaid` được mở thanh toán; thiếu trạng thái tiền thì không hiện nút.
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
      "qr_url": "https://example.com/payment-qr.png",
      "bank_name": "Tên ngân hàng",
      "account_name": "Tên người nhận",
      "account_number": "Số tài khoản",
      "content": "Nội dung chuyển khoản của giao dịch"
    }
  }
}
```

Trạng thái: `pending`, `confirming`, `paid`, `failed`, `cancelled`, `expired`. Trạng thái khác được hiển thị là chưa xác định và cho kiểm tra lại, tuyệt đối không suy thành thành công. `transfer` chỉ cần cho QR; không nhúng thông tin tài khoản mẫu. Chọn cash trả pending, chỉ nhân viên/backend xác nhận sau thu tiền. Thanh toán không tự giao sách; kích hoạt VIP thuộc backend, frontend tải lại membership khi nhận paid.

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
