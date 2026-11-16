# Chạy Thư viện Mây bằng Docker

Bản Compose có Apache/PHP, MySQL, một tác vụ chạy migration và một tiến trình chạy lịch Laravel. Website mặc định chỉ mở trên máy đang chạy Docker. MySQL và tệp trong storage/app được giữ trong hai volume riêng.

## Khởi chạy trên máy sạch

1. Sao chép .env.docker.example thành .env.docker. Tạo riêng DB_PASSWORD và MYSQL_ROOT_PASSWORD bằng trình tạo mật khẩu. Dùng tên database và tài khoản ứng dụng có sẵn trong file mẫu.
2. Chạy lệnh sau để tạo khóa ứng dụng, rồi lưu kết quả vào APP_KEY trong .env.docker.

   ~~~sh
   docker run --rm php:8.3-cli-bookworm php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
   ~~~

3. Tại thư mục chứa compose.yaml, chạy:

   ~~~sh
   docker compose --env-file .env.docker build migrate
   docker compose --env-file .env.docker up -d
   docker compose --env-file .env.docker ps -a
   ~~~

4. Mở http://127.0.0.1:8080. Kiểm tra http://127.0.0.1:8080/ready và xem trả về status ready. Nếu cổng bận, đổi WEB_PORT và APP_URL cùng lúc.

Bản Docker khởi tạo schema trống. Để thêm truyện EPUB dành cho trình diễn, chạy:

~~~sh
docker compose --env-file .env.docker exec -T web runuser -u www-data -- php artisan db:seed --class=DemoEpubSeeder --force
~~~

Để cấp tài khoản Admin riêng, chạy lệnh dưới đây trong terminal tương tác. Lệnh hỏi mật khẩu ở trường ẩn. Dùng thông tin tài khoản thật của người quản trị máy chủ.

~~~sh
docker compose --env-file .env.docker exec web runuser -u www-data -- php artisan library:admin
~~~

Chức năng quên mật khẩu cần SMTP. Cấu hình MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_SCHEME (khi nhà cung cấp yêu cầu), MAIL_USERNAME, MAIL_PASSWORD và MAIL_FROM_ADDRESS trong .env.docker. GEMINI_API_KEY chỉ dùng nếu muốn mở Trợ lý Mây.

## HTTPS cho portfolio

Trỏ DNS của một tên miền bạn kiểm soát đến máy chủ Docker. Mở cổng 80 và 443 cho Caddy. Đặt SITE_HOST bằng tên miền, APP_URL bằng địa chỉ HTTPS tương ứng, SESSION_SECURE_COOKIE=true và TRUSTED_PROXIES=* trong .env.docker. Với cấu hình Compose này, Docker chỉ công bố cổng Apache trên loopback và Caddy nhận lưu lượng từ bên ngoài. Nếu đổi cách kết nối mạng, giới hạn TRUSTED_PROXIES ở các proxy bạn vận hành.

~~~sh
docker compose --env-file .env.docker --profile https up -d
~~~

Mở trang bằng tên miền và thử đăng nhập, kiểm tra ảnh, EPUB và đường dẫn /ready. Bản Docker luôn tắt APP_DEBUG. Giữ .env.docker và khóa ứng dụng ngoài Git. Với bản portfolio công khai, tạo database riêng và cấp quyền nhân viên cho người được phép.

## Sao lưu

Các lệnh dưới đây dùng Bash hoặc WSL để giữ nguyên byte khi chuyển luồng tar. Chọn một thư mục riêng được bảo vệ và chạy lệnh từ thư mục chứa compose.yaml. Tạm dừng thao tác nhập sách trong lúc sao lưu để database và tệp ảnh khớp nhau.

~~~sh
umask 077
docker compose --env-file .env.docker exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' > may-database.sql
docker compose --env-file .env.docker exec -T web tar -C /var/www/html/storage/app -czf - . > may-files.tar.gz
~~~

Giữ riêng bản sao .env.docker, nhất là APP_KEY. Tệp sao lưu chứa dữ liệu của thư viện và cần được lưu trong khu vực hạn chế truy cập.

## Khôi phục thử

Tạo một thư mục chạy riêng có cùng mã nguồn, lấy bản sao file môi trường đã bảo vệ và chọn cổng khác. Đặt tên dự án Compose mới để dùng volume mới. Ví dụ:

~~~sh
docker compose -p may-restore --env-file .env.docker up -d db
docker compose -p may-restore --env-file .env.docker exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < may-database.sql
docker compose -p may-restore --env-file .env.docker up -d
docker compose -p may-restore --env-file .env.docker exec -T -u www-data web \
  tar --no-same-owner -xzf - -C /var/www/html/storage/app < may-files.tar.gz
~~~

Chờ database báo healthy trước khi nạp SQL. Dùng SQL và tar do bạn vừa tạo trong bước sao lưu. Sau khi mở bản phục hồi, đối chiếu số sách, mở ảnh bìa và thử đọc EPUB. Dừng bản phục hồi bằng docker compose down với cùng tên dự án.
