# Danh mục sách dùng để demo

- `php artisan db:seed --class=RealBookCatalogSeeder` thêm 10 đầu sách giấy với ảnh bìa, ảnh giới thiệu và phí mượn mẫu.
- `php artisan db:seed --class=ClassicEpubCatalogSeeder` thêm *Tắt đèn*, *Truyện Kiều* và *Lục Vân Tiên*. Mỗi đầu có năm bản giấy cùng một tệp EPUB.
- Các seeder trên giữ nguyên các đầu sách đã có. Chạy `php artisan storage:link` để hiện ảnh bìa và bật PHP ZIP để đọc EPUB.
- Có thể tải EPUB được phép sử dụng lên từ **Kho sách → Tải EPUB**. Bản trích *Số đỏ* của thư viện cục bộ cần được nhập riêng. Tài khoản, lịch sử giao dịch và dữ liệu MySQL cục bộ thuộc môi trường đã tạo chúng.

Văn bản cổ điển được lấy từ Wikisource và định dạng lại thành EPUB cho trình đọc. Các đường dẫn văn bản và ảnh lưu trong `database/demo-catalog/catalog.json`.

- [Tắt đèn](https://vi.wikisource.org/wiki/T%E1%BA%AFt_%C4%91%C3%A8n), [ảnh bìa](https://commons.wikimedia.org/wiki/File:Tat_den.pdf)
- [Truyện Kiều](https://vi.wikisource.org/wiki/Truy%E1%BB%87n_Ki%E1%BB%81u), [ảnh bìa](https://commons.wikimedia.org/wiki/File:ThuykieuTruyen.jpg)
- [Lục Vân Tiên](https://vi.wikisource.org/wiki/L%E1%BB%A5c_V%C3%A2n_Ti%C3%AAn_%28b%E1%BA%A3n_Qu%E1%BB%91c_ng%E1%BB%AF_2082_c%C3%A2u%29), [ảnh bìa](https://commons.wikimedia.org/wiki/File:LucVanTientruyen.JPG)
