# Bàn giao backend: tài khoản và thanh toán đặt trước

## 1. Current implementation status

This integration branch includes the reservation payment workflow, manual cash and simulated-transfer confirmation, VIP membership, profile gender, and protected EPUB reading APIs. AI chat remains disabled and is not implemented. The later sections retain the original handoff notes; where they describe work as pending, use the current routes, controllers, migrations, and tests as the source of truth.

The original “Đã giữ” symptom came from the earlier implementation having no reservation payment state. This branch adds that state and the payment APIs described below. The layout keeps the feature flags off until the matching additive migrations have been applied to the target database; never enable them to conceal a missing schema. The current payload and UI behavior are documented in [frontend-feature-contracts.md](frontend-feature-contracts.md).

## 2. Implemented source map

| Vị trí | Trách nhiệm hiện tại / việc cần thay đổi |
| --- | --- |
| `routes/web.php` | Session-authenticated routes for profiles, reservations, payments, VIP, EPUB and staff operations. |
| `app/Http/Controllers/ReaderCartController.php` | Creates cart reservations atomically and records an explicitly selected payment method when supplied. |
| `app/Http/Controllers/ReservationController.php` | Preserves FIFO inventory holds and the 48-hour window; separates approval, payment confirmation and handover. |
| `app/Http/Controllers/PaymentController.php` | Owns quotes, idempotent payments, simulated-transfer reports, staff confirmation, and VIP membership grants. |
| `app/Services/LoanIssuanceService.php` | Keeps legacy null-payment reservations compatible and requires paid workflow payments for handover without charging twice. |
| `app/Http/Controllers/AuthController.php` | Reads and updates reader and staff profiles, including the nullable supported gender values. |
| `routes/reading.php`, `app/Http/Controllers/EpubReadingController.php` | Serves protected EPUB reading and staff uploads; free readers get three chapters and active VIP readers get the full book. |
| `resources/views/layouts/library.blade.php` | Injects the feature flags before deferred scripts; keep them off until migrations are applied. |

The additive feature migrations are `2026_10_20_110000_add_gender_to_profile_tables`, `2026_10_20_120000_create_reservation_payment_workflow`, and `2026_10_20_130000_add_epub_path_to_books`. Check `php artisan migrate:status` before deployment and apply only pending migrations with `php artisan migrate`; do not use `migrate:fresh` or repeat the sample-data seeder as part of this integration.

## 3. Quy tắc nghiệp vụ cần giữ

| Sự kiện | reservation.status | payment_status ở API reservation | Độc giả | Thủ thư |
| --- | --- | --- | --- | --- |
| Gửi yêu cầu | `pending` | `unpaid` cho yêu cầu mới | Chờ duyệt | Chờ duyệt |
| Duyệt và giữ bản sách trong kho | `ready` | `unpaid` | Chờ thanh toán + nút Thanh toán | Chờ khách chọn/thanh toán |
| Tạo payment QR | `ready` | `pending` | Trang QR, chưa trả tiền | Chờ khách chuyển khoản |
| Độc giả báo đã chuyển | `ready` | `confirming` | Chờ xác nhận thanh toán | Cần kiểm tra chuyển khoản |
| Chọn tiền mặt / xem hướng dẫn tại quầy | `ready` | `pending` | Chờ thanh toán tiền mặt | Chờ thu tiền mặt |
| Thủ thư xác nhận đã nhận đủ tiền | `ready` | `paid` | Đã giữ + thông tin đã thanh toán | Thanh toán thành công + Giao sách |
| Thủ thư bàn giao sách | `fulfilled` | `paid` | Đã giao | Đã giao, có phiếu mượn |
| Hết hạn trước khi nhận sách | `expired` | Giữ nguyên chứng cứ thanh toán | Hết hạn nhận sách; vẫn hiện đã thanh toán nếu có | Xử lý nghiệp vụ theo biên nhận |

- Một hạn duy nhất: 48 giờ kể từ lúc duyệt, dùng cho cả thanh toán và nhận sách. Báo đã chuyển / chọn tiền mặt / xác nhận tiền không gia hạn.
- `ready` vẫn chiếm số lượng giữ trong kho, kể cả chưa thu tiền. Giữ FIFO, khóa tồn kho và không tăng số bản khả dụng chỉ vì đang chờ thanh toán.
- Độc giả phải chọn được QR hoặc tiền mặt sau khi duyệt. Phương thức lúc gửi giỏ là dự kiến; chỉ khóa phương thức khi tạo giao dịch. Khi đã có giao dịch, trả lại giao dịch đó thay vì tạo khoản thu khác.
- QR: độc giả báo đã chuyển chỉ là lời báo; thủ thư kiểm tra và xác nhận. Cash: chỉ thủ thư xác nhận đã nhận đủ tiền. Không có auto-paid phía client.
- Giao sách là một bước riêng; hạn mượn 14 ngày bắt đầu lúc bàn giao. Payment paid không tự tạo loan.
- Yêu cầu đã trả tiền nhưng hết hạn/cần hủy không được tự xóa biên nhận hoặc tự báo hoàn tiền. Bổ sung quy trình hoàn tiền riêng nếu thư viện yêu cầu.

## 4. Database đề xuất cho backend

Tạo migration mới theo hướng thêm bảng/cột; không sửa nội dung migration đã chạy. Các kiểu dữ liệu dưới đây là thiết kế đề xuất để triển khai, chưa phải schema có sẵn.

### 4.1 Bổ sung `reservations`

| Cột | Kiểu / mặc định | Ý nghĩa |
| --- | --- | --- |
| `payment_status` | varchar(20), nullable, mặc định null | null là dữ liệu cũ chưa đối soát; yêu cầu mới gán rõ `unpaid`; các giá trị `unpaid`, `pending`, `confirming`, `paid`, `failed`, `cancelled`, `expired`. |
| `payment_method` | varchar(30), nullable | `cash` hoặc `bank_transfer`; trước khi tạo payment có thể null hoặc là lựa chọn dự kiến. |
| `amount` | decimal(12,2), nullable | Snapshot phí mượn tại lúc duyệt. Tính từ `books.rental_fee × quantity` ở server. Không dùng float để tính tiền. |
| `currency` | char(3), nullable | `VND` cho khoản mới; không tự bổ sung tiền tệ cho biên nhận cũ chưa kiểm tra. |

Giữ các cột hiện có `ready_at`, `expires_at`, `approved_by`, `fulfilled_at`. Index `(reader_id, status)`, `(status, expires_at)`, `payment_status` nếu cần danh sách xử lý. `amount` API có thể serialize từ snapshot chuẩn hóa; tổng thanh toán luôn lấy từ báo giá server.

### 4.2 Thêm `payment_quotes`

| Cột | Kiểu / ràng buộc |
| --- | --- |
| `id` | UUID/ULID hoặc chuỗi opaque, primary key |
| `reader_id` | FK users, index, restrict delete |
| `purpose` | varchar: `reservation` hoặc `vip` |
| `reservation_id` | FK nullable, index; bắt buộc khi purpose reservation |
| `vip_plan_id` | FK nullable, index; bắt buộc khi purpose vip; có thể thêm ở phase VIP |
| `total_amount`, `currency` | decimal(12,2), char(3) |
| `items_snapshot` | JSON danh sách `{title, quantity, unit_amount, line_amount}`; không đổi theo giá sách sau này |
| `allowed_methods` | JSON danh sách phương thức hợp lệ theo cấu hình thật |
| `expires_at` | timestamp; không vượt hạn nhận sách |
| `created_at`, `updated_at` | timestamps |

Frontend không được quyết định giá bằng body gửi lên. Báo giá phải thuộc tài khoản gọi; trả lỗi nếu yêu cầu chưa duyệt, đã hủy, đã hết hạn hoặc không còn đủ điều kiện.

### 4.3 Thêm `payments` và `payment_events`

| Cột trong `payments` | Kiểu / ràng buộc |
| --- | --- |
| `id` | UUID/ULID hoặc opaque key |
| `reader_id`, `quote_id` | FK, index; quote snapshot gắn với người trả |
| `reservation_id` | FK nullable, **unique** cho mô hình một giao dịch/đặt trước; nhiều payment VIP vẫn dùng null |
| `purpose` | `reservation` / `vip` |
| `method` | `cash` / `bank_transfer` |
| `status` | `pending`, `confirming`, `paid`, `failed`, `cancelled`, `expired` |
| `amount`, `currency` | decimal(12,2), char(3), lấy từ quote |
| `idempotency_key` | varchar(100); unique `(reader_id, idempotency_key)` |
| `request_fingerprint` | hash quote/method; cùng key nhưng body khác trả 409 |
| `transfer_reference` | chuỗi unique nullable, nội dung QR phân biệt từng giao dịch |
| `transfer_snapshot` | JSON nullable: ngân hàng, tài khoản nhận, nội dung, đường dẫn QR từ cấu hình thật |
| `reported_at` | timestamp nullable, lúc độc giả báo đã chuyển |
| `confirmed_at`, `confirmed_by` | timestamp + FK users nullable; người thực thu/xác nhận |
| `receipt_note` | varchar(500) nullable |
| `created_at`, `updated_at` | timestamps |

Một payment/reservation có thể được thử lại trên cùng bản ghi khi lỗi hợp lệ; lưu lịch sử bằng `payment_events` (`id`, `payment_id`, `actor_id` nullable, `from_status`, `to_status`, `event_type`, `note` nullable, `created_at`). Không ghi đè paid thành pending khi request cũ được gửi lại. Nếu backend cần nhiều giao dịch cho một reservation, phải thay ràng buộc unique bằng mô hình chỉ một khoản đang hoạt động/đã trả và cùng sửa chính sách retry; không bỏ ràng buộc chống thu trùng mà không thay thế.

### 4.4 Liên kết biên nhận với `loans`

Thêm `reservation_id` nullable unique và `payment_id` nullable unique vào `loans` bằng migration mới. Loan trực tiếp tại quầy vẫn dùng nghiệp vụ thu phí hiện có. Loan từ đặt trước phải:

1. Khóa reservation + payment, kiểm tra paid, ready, còn hạn và chưa có loan.
2. Dùng snapshot khoản đã thu cho `rental_fee_total`, `rental_fee_received`, thời điểm/người thu/phương thức; không tính lại giá mới để bắt khách trả lần hai.
3. Tạo loan/items, ghi liên kết và cập nhật reservation `fulfilled` trong cùng transaction. Gửi lại handover trả cùng loan, không tạo lần hai.

## 5. API bắt buộc để khắc phục lỗi đặt trước

Tất cả endpoint dùng session hiện có, middleware `auth`, `active`, role tương ứng; POST gửi CSRF. ID trong ví dụ dưới là minh họa cấu trúc, không phải dữ liệu đã tồn tại.

| API | Payload / response và hành vi |
| --- | --- |
| POST `/my/cart/reservations` (sửa) | Nhận `{payment_method?: 'cash'|'bank_transfer'}`. Tạo yêu cầu mới `pending`, `payment_status=unpaid`, trả `{reservations:[...]}`; xóa giỏ chỉ sau commit. POST `/reservations` cũng cần cùng chính sách nếu còn dùng. |
| POST `/staff/reservations/{id}/approve` (thêm) | Frontend hiện gửi `{payment_method}` nếu có. Backend không được lấy body này làm bằng chứng đã thu. Giữ FIFO/tồn kho hiện có; snapshot số tiền, ready + unpaid, hạn 48 giờ; trả `{reservation}`. `/ready` cũ nên gọi chung service để không lệch trạng thái. |
| GET `/my/reservations`, GET `/staff/reservations` (sửa) | Giữ paginator `{data,...}`. Mỗi row thêm `payment_status`, `payment_method`, `amount`, `currency`, `expires_at`, quan hệ book/reader như hiện có. Reader chỉ xem của mình. |
| POST `/my/payment-quotes` (thêm) | `{purpose:'reservation',reference_id:'7'}` → `{quote}` như mẫu dưới. Khi chưa có payment, `methods` gồm các phương thức được cấu hình; nếu QR chưa cấu hình, chỉ cho cash và thông báo rõ. |
| POST `/my/payments` (thêm) | `{quote_id,method,idempotency_key}` → `{payment}`; khóa reservation và tái kiểm tra thời hạn/quyền/snapshot, trả giao dịch cũ khi lặp. Cash vẫn pending. QR trả thông tin nhận tiền thật; không có cấu hình QR thì từ chối phương thức đó. |
| GET `/my/payments/{id}` (thêm) | `{payment}` của chính độc giả; trạng thái server, không suy paid từ báo đã chuyển. |
| POST `/my/reservations/{id}/transfer` (thêm) | `{payment_id}`; chỉ bank_transfer thuộc reservation/tài khoản, chưa hết hạn; pending → confirming; trả **`{payment, reservation}`**. Frontend cần `payment` ở response này. |
| POST `/staff/reservations/{id}/confirm-payment` (thêm) | Frontend gửi `{method}`; kiểm tra đúng method của payment, QR đang confirming hoặc cash đang chờ thu. Ghi người/thời điểm nhận, paid + audit trong transaction; trả `{reservation,payment}`. Không nhận giá tiền do client quyết định. |
| POST `/staff/reservations/{id}/handover` (thêm) | Frontend hiện gửi `{method}`; xác thực paid + hạn giữ, tạo loan một lần theo mục 4.4; trả `{reservation,loan}`. Không thu lại tiền. |

Mẫu báo giá:

```json
{"quote":{"id":"q-opaque","purpose":"reservation","reference_id":"7","currency":"VND","total_amount":"10000.00","expires_at":"2027-01-01T12:00:00+07:00","methods":["bank_transfer","cash"],"items":[{"title":"Tên sách","quantity":2,"unit_amount":"5000.00","line_amount":"10000.00"}],"reservation":{"id":7,"status":"ready","expires_at":"2027-01-01T12:00:00+07:00","payment_status":"unpaid"}}}
```

Mẫu payment:

```json
{"payment":{"id":"p-opaque","quote_id":"q-opaque","method":"bank_transfer","status":"pending","transfer":{"qr_url":"URL_QR_TU_CAU_HINH_THAT","bank_name":"TEN_NGAN_HANG","account_name":"TEN_NGUOI_NHAN","account_number":"SO_TAI_KHOAN","content":"MA_GIAO_DICH_DUY_NHAT"}}}
```

Các chuỗi viết hoa là placeholder trong tài liệu, không được dùng làm dữ liệu nhận tiền. URL QR phải truy cập được qua HTTP(S); QR chứa số tiền và nội dung đúng snapshot. Cấu hình bên server bằng config/env, không đưa khóa dịch vụ vào JS.

Response lỗi dùng HTTP 401/403/404/409/422 phù hợp và `{message: 'Thông báo tiếng Việt', errors?: {...}}`. Mã 409 cho xung đột trạng thái/idempotency; 422 cho dữ liệu không hợp lệ. Không trả 200 thành công khi chưa lưu khoản thu.

## 6. Migration và bổ sung dữ liệu cũ

1. Sao lưu database, ghi số bản ghi reservations/loans và kết quả migrate:status; thực hiện trước trên bản sao dữ liệu.
2. Chạy các migration nền còn thiếu bằng `php artisan migrate`. Không dùng `migrate:fresh`, không xóa hoặc tạo lại bảng hiện có.
3. Thêm bảng quote/payments/events, thêm cột nullable vào reservations và FK nullable vào loans. Đợt triển khai đầu vẫn tắt capability.
4. Dùng một Artisan command có chế độ dry-run, chạy theo chunk và lặp an toàn để đối soát. Không chạy UPDATE hàng loạt `ready → paid`.
5. Với yêu cầu `pending`: backend có thể gán `payment_status=unpaid` vì luồng cũ chưa thu tại bước này. Phương thức vẫn null nếu người dùng chưa chọn.
6. Với `ready` còn hạn: thủ thư kiểm tra chưa có khoản thu; khi xác nhận chưa thu, snapshot phí hiện hành, gán unpaid; giữ nguyên `ready_at`/`expires_at`. Nếu không có bằng chứng rõ, giữ null và đưa vào danh sách đối soát. Các yêu cầu này chỉ hiện nút thanh toán sau khi đã được phân loại unpaid.
7. Với `fulfilled`/loan cũ: chỉ tạo lịch sử payment paid khi có biên nhận khớp `rental_fee_received`, thời điểm/người thu và liên kết reservation chắc chắn. Dữ liệu cũ không có liên kết loan/reservation thì không ghép chỉ theo tên sách/người mượn. Không phát sinh khoản thu mới cho loan cũ.
8. Với `cancelled`/`expired`: giữ lịch sử; không gán paid/unpaid nếu không có dữ liệu đối soát. Khoản đã paid vẫn giữ nguyên chứng cứ khi reservation hết hạn.
9. Thêm dữ liệu danh mục ngân hàng/gói VIP qua seeder quản trị chạy lặp an toàn (`updateOrCreate` theo mã ổn định) hoặc màn cấu hình; không dùng seeder để ghi đè giao dịch/lịch sử thực tế.
10. Đối chiếu tổng tiền và số lượng trước/sau, thử rollback ứng dụng bằng cách tắt capability; giữ bảng dữ liệu tài chính khi rollback code. Migration down không được tự xóa dữ liệu payment đã phát sinh.

Truy vấn đọc để phân loại dữ liệu trước khi viết command backfill:

```sql
SELECT status, COUNT(*) AS total FROM reservations GROUP BY status;
SELECT id, reader_id, book_id, quantity, ready_at, expires_at
FROM reservations WHERE status = 'ready' ORDER BY id;
SELECT id, reader_id, rental_fee_total, rental_fee_received,
       rental_fee_received_at, rental_fee_received_by, rental_fee_payment_method
FROM loans ORDER BY id;
```

## 7. Điểm cần phối hợp frontend trước khi bật thật

- `accountFeatures.loadQuote()` hiện chỉ nhận báo giá cho reservation unpaid; chưa phục hồi payment pending/confirming/paid sau reload. Thống nhất response lấy giao dịch hiện có (ví dụ `{quote,payment}`), rồi sửa loader để đọc payment đó. Backend phải trả cùng khoản hiện có, không tạo mới khi quay lại trang.
- `paymentAction()` hiện chỉ hiện cho unpaid. Bổ sung thao tác “Xem thanh toán” cho pending/confirming/paid để độc giả mở lại QR/biên nhận. Giữ việc cấm tạo khoản thu mới khi đã paid/hết hạn.
- `updatePayment()` hiện chỉ tự tải lại membership khi paid. Với reservation cần tải lại danh sách độc giả sau khi được xác nhận; làm mới khi quay lại tab/trang để nhãn “Đã giữ” phản ánh dữ liệu server mới.
- Danh sách thủ thư đang chờ `payment_status=confirming` cho QR và unpaid/pending cho cash. Chuẩn hóa enum trên cả API và UI; không gửi `success` thay `paid`.
- Luồng legacy `/fulfill` vẫn gộp thu và giao. Khi workflow mới bật, phải chặn việc gọi đường cũ để thu lại một payment đã paid, kể cả gọi URL trực tiếp.
- Bổ sung UI xác minh biên nhận/ghi chú nếu backend yêu cầu trường ngoài `{method}` hiện frontend gửi; cùng cập nhật hợp đồng rồi mới bật.

Sau khi hoàn tất API + migration + các điểm trên, inject cấu hình trước deferred scripts trong layout:

```js
window.libraryFeatureConfig = {
  capabilities: { payments: true, reservationWorkflow: true },
};
```

Giữ cờ tắt ở môi trường chưa migrate hoặc chưa có cấu hình nhận tiền. Không đổi mặc định của `feature-api.js` để che lỗi triển khai.

## 8. Các phần backend khác cho giao diện đã có

| Phần | API | Database cần bổ sung |
| --- | --- | --- |
| Giới tính | GET/PATCH `/my/profile`, giữ `{user,profile}` | nullable `gender` varchar(30) ở `reader_profiles` cho độc giả và `users` cho nhân viên theo mô hình hồ sơ hiện tại. Giá trị null/male/female/other/prefer_not_to_say; chuỗi rỗng multipart chuẩn hóa null. Cập nhật fillable, validation, payload và xử lý chuyển vai trò. Không backfill “không muốn chia sẻ” cho null. Chỉ bật `gender` sau khi lưu và đọc lại được. |
| Gói hiện tại | GET `/my/membership` | `memberships` (user, plan nullable, status, starts_at, expires_at, payment_id nullable unique); không suy VIP chỉ từ lời chọn trả tiền mặt. Free là trạng thái được API xác nhận rõ. |
| Gói VIP | GET `/membership/plans` | `membership_plans`: mã ổn định, tên, price decimal, currency, duration, benefits JSON, active. API trả `id,name,price,duration_label,benefits` như frontend chờ. Giá/quyền lợi nhập từ chính sách thư viện. |
| Thu tiền VIP | Quote/payment API dùng `purpose=vip` | Quote snapshot plan, liên kết membership/payment; chỉ tạo/gia hạn membership khi paid trong transaction, một payment chỉ kích hoạt một lần. Chính sách cộng hạn khi đang VIP phải chốt và áp dụng tại server. |
| Chat hỗ trợ | POST `/support/chat` → `{answer,books:[{id,title}]}` | Không bắt buộc table chat: hội thoại hiện giữ trong bộ nhớ tab. Backend gọi dịch vụ AI bằng khóa server, giới hạn tần suất/ngữ cảnh; chỉ danh mục công khai, không thao tác đặt/thu tiền hoặc đọc hồ sơ mượn riêng tư. |

## 9. Kiểm tra nghiệm thu backend

- Thủ thư duyệt → GET độc giả trả ready + unpaid + expires_at + amount → có nút Thanh toán; chọn được QR hoặc cash khả dụng.
- QR trả thông tin thật; báo đã chuyển chỉ thành confirming. Người khác/độc giả không thể gọi confirm-payment hoặc xác nhận khoản của tài khoản khác.
- Cash pending cho tới khi thủ thư xác nhận. Chọn cash không kích hoạt VIP hoặc báo paid.
- Request lặp, hai tab, timeout rồi gửi lại, hai thủ thư thao tác đồng thời: chỉ một khoản paid và một loan; cùng idempotency key/body khác bị từ chối.
- Đã hủy/hết hạn/chưa duyệt/đã paid không thể tạo giao dịch mới, kể cả gọi trực tiếp API.
- Sau reload vẫn mở lại QR/biên nhận hiện có; danh sách hai vai trò khớp cùng trạng thái.
- Paid rồi giao sách dùng khoản đã thu, không tính giá mới để thu thêm; hạn mượn bắt đầu lúc giao.
- Không reset hạn 48 giờ khi thanh toán; hết hạn vẫn bảo toàn khoản đã thu và nhật ký.
- Migration/backfill giữ nguyên dữ liệu/tồn kho/biên nhận cũ; dữ liệu chưa đối soát không tự chuyển paid.
- Kiểm tra nghiệp vụ loan trực tiếp, FIFO, tồn kho, đăng nhập/hồ sơ và trả sách/phạt vẫn hoạt động.

Dùng database test riêng (phpunit mặc định SQLite memory), không dùng database vận hành cho RefreshDatabase. Có thể chạy `php artisan test` sau khi thêm kiểm thử API và transaction; frontend hiện có `node --test tests/frontend/account-features.test.cjs`, cần mở rộng fixture theo hợp đồng phục hồi payment ở mục 7 khi triển khai phần đó.
