# Thư viện Mây chạy trên Laravel

Frontend đã được chuyển từ `D:\HUIT\MaNguonMo\Frontend_MNM\dist` sang một dự án Laravel 12, dùng PHP 8.3 của Laragon.

## Chạy trên máy hiện tại

Cách 1: Mở Laragon Terminal và chạy:

```powershell
cd C:\laragon\www\thu-vien-may
php artisan serve --host=127.0.0.1 --port=8000
```

Truy cập `http://127.0.0.1:8000/`. Giữ terminal mở khi sử dụng.

Cách 2: Nhấp đúp `chay-laravel.bat`. Tệp này dùng trực tiếp đường dẫn PHP của Laragon trên máy hiện tại nên không cần thêm PHP vào PATH của Windows.

Cách 3: Khi Apache của Laragon đang chạy với thư mục web mặc định, mở `http://localhost/thu-vien-may/public/`. URL `http://localhost/thu-vien-may/` chuyển đến thư mục public. Khi dùng virtual host, Document Root phải trỏ đến `C:/laragon/www/thu-vien-may/public`.

Không cần chạy `npm install`, `npm run dev` hoặc MySQL để xem frontend hiện tại. CSS và JavaScript được phục vụ trực tiếp từ `public/library`.

## Cấu trúc cần chỉnh sửa

- `routes/web.php`: route trang chủ mang tên `library`.
- `app/Http/Controllers/LibraryPageController.php`: trả về trang Blade.
- `resources/views/layouts/library.blade.php`: HTML, metadata và đường dẫn tài nguyên qua `asset()`.
- `resources/views/library/index.blade.php`: bố cục trang thư viện.
- `resources/views/library/partials/`: menu, topbar, footer và hộp thoại.
- `public/library/`: bốn tệp JavaScript và ba tệp CSS được chuyển nguyên trạng từ frontend gốc.

Sau khi chuyển, hãy chỉnh sửa giao diện tại dự án Laravel này. Thay đổi trong thư mục `dist` gốc không tự đồng bộ sang dự án Laravel.

## Phạm vi đã tích hợp

Trang được trả về qua Laravel Controller và Blade. Đăng ký, đăng nhập và đăng xuất hiện dùng Laravel session, Eloquent `User`, mật khẩu băm và validation phía máy chủ. Vai trò được lưu trong cột `users.role`; Middleware `role` bảo vệ route nhân viên. Các trang trong giao diện vẫn điều hướng bằng hash (`/#catalog`, `/#login`, ...), còn nội dung nghiệp vụ sách, giỏ, phiếu mượn và dữ liệu quản trị đang tiếp tục là dữ liệu trình diễn phía trình duyệt.

Các tài khoản trình diễn được giữ nguyên:

| Vai trò | Email | Mật khẩu mẫu |
| --- | --- | --- |
| Admin | admin@library.vn | admin12345 |
| Thủ thư | thuthu@library.vn | thuthu123 |
| Độc giả | minhanh@example.com | docSach123 |

Để tạo các tài khoản mẫu và cấu trúc dữ liệu, chạy trong thư mục dự án:

```powershell
php artisan migrate --seed
```

Đăng ký từ giao diện tạo tài khoản độc giả mới trong database. Các route `POST /auth/login`, `POST /auth/register`, `POST /auth/logout` phục vụ form; `GET /auth/session` trả về phiên hiện tại. Route `GET /staff` yêu cầu đăng nhập với vai trò Admin hoặc Thủ thư. Session và cache Laravel dùng file.

Để giữ cùng dữ liệu trình diễn khi sử dụng, hãy dùng nhất quán một URL. `localhost` và `127.0.0.1:8000` có vùng lưu trữ trình duyệt khác nhau.

## Khi chuyển sang máy khác

Chạy `composer install`, sao chép `.env.example` thành `.env`, sau đó chạy `php artisan key:generate`. Chỉnh đường dẫn PHP trong `chay-laravel.bat` nếu Laragon dùng phiên bản khác, hoặc chạy trực tiếp từ Laragon Terminal.
