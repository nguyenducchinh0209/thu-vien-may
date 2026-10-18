# Thư viện Mây

Đồ án website quản lý thư viện, sử dụng Laravel 12, Blade, CSS và JavaScript.

## Chức năng hiện tại

- Giao diện khám phá, danh mục, tìm kiếm và chi tiết sách, giỏ sách, kệ sách, hồ sơ và đọc thử.
- Giao diện quản trị và nghiệp vụ thủ thư.
- Đăng ký, đăng nhập, đăng xuất bằng Laravel session và Eloquent User; mật khẩu được băm và dữ liệu nhập được kiểm tra phía máy chủ.
- Ba vai trò: Admin, Thủ thư và Độc giả; có middleware kiểm tra vai trò cho route nhân viên.

Phần quản lý sách, tác giả, tài khoản trong bảng quản trị, phiếu mượn/trả, giỏ sách và dữ liệu nghiệp vụ khác hiện vẫn là bản trình diễn lưu trong trình duyệt. Các phần này cần tiếp tục nối với database Laravel. Dữ liệu tìm kiếm và phân trang sách hiện do JavaScript xử lý. AI, thanh toán và đọc EPUB đầy đủ chưa được tích hợp.

## Cài đặt và chạy

Yêu cầu PHP 8.2 trở lên có SQLite/PDO SQLite, Composer và Git. Có thể dùng Laragon Terminal trên Windows.

```powershell
git clone https://github.com/nguyenducchinh0209/thu-vien-may.git
cd thu-vien-may
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan migrate --seed
php artisan serve
```

Mở `http://127.0.0.1:8000/`. Giao diện hiện dùng tài nguyên trong `public/library`, không cần chạy npm để xem website.

Các tài khoản mẫu được tạo bằng seeder, chỉ dùng cho trình diễn ở môi trường local:

| Vai trò | Email | Mật khẩu mẫu |
| --- | --- | --- |
| Admin | admin@library.vn | admin12345 |
| Thủ thư | thuthu@library.vn | thuthu123 |
| Độc giả | minhanh@example.com | docSach123 |

## Cấu trúc chính

- `app/Http/Controllers`: controller trang thư viện và xác thực.
- `app/Http/Middleware`: middleware kiểm tra vai trò.
- `resources/views`: layout và giao diện Blade.
- `public/library`: CSS và JavaScript của giao diện.
- `database/migrations`, `database/seeders`: cấu trúc database và tài khoản mẫu.
- `routes/web.php`: route của website.

Xem thêm [hướng dẫn Laragon](README-THU-VIEN-MAY.md). Tệp `.env`, database SQLite, session, log, cache và thư mục `vendor` được loại khỏi Git; mỗi máy tự tạo môi trường và database khi cài đặt.

---

Thông tin framework Laravel dùng làm nền tảng của đồ án:

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
