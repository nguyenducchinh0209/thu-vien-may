# Kịch bản trình diễn Thư viện Mây

## Chuẩn bị

- Chạy MySQL và website. Dùng ba tài khoản riêng cho Độc giả, Thủ thư và Admin, mỗi vai trò ở một hồ sơ trình duyệt nếu cần chuyển nhanh.
- Chọn một đầu sách giấy còn bản. Chuẩn bị một phiếu cần thu thêm và một phiếu cần hoàn cọc để có thể đi thẳng vào hai nhánh trong phần đối soát.
- Tìm **Nhật ký Thư viện Mây**. Nếu chưa có, chạy php artisan db:seed --class=DemoEpubSeeder bằng PHP đã bật ZIP. Đây là năm mẩu chuyện gốc tiếng Việt kèm đồ án, được cung cấp để trình diễn và chia sẻ bản demo.

## Demo trong 10–15 phút

1. Độc giả tìm sách theo tên hoặc tác giả, xem số bản còn và gửi yêu cầu đặt giấy. Mở danh sách đặt trước để theo dõi tiến trình.
2. Thủ thư vào Tổng quan và bấm ô việc chờ duyệt. Duyệt yêu cầu để giữ một bản trong 48 giờ. Độc giả thấy trạng thái mới và chọn cách thanh toán.
3. Thủ thư xác nhận phí trong hàng chờ thanh toán rồi giao sách. Chỉ ra cọc 100.000đ mỗi bản, gồm 70.000đ cọc mất sách và 30.000đ cọc giữ sách. Chọn hạn trả và mở phiếu vừa phát hành để xem phần in.
4. Tìm phiếu theo mã, ghi nhận trả một phần nếu phiếu có nhiều bản, rồi nhận phần còn lại. Từ chi tiết phiếu, lập một phiếu phạt hư hỏng. Nếu trả trễ, trang nhận trả hiển thị số tiền dự kiến, máy chủ tính lại khi ghi nhận.
5. Mở phiếu chờ tất toán. Trình bày khấu trừ, hoàn cọc hoặc khoản cần thu thêm. Xác nhận khi đã xử lý tiền, rồi mở biên nhận. Admin xem báo cáo thu, hoàn và cọc, xuất CSV và đối chiếu một dòng với phiếu.
6. Quay lại vai trò Độc giả. Phiếu giấy ở kệ sách chỉ có chi tiết phiếu. Mở EPUB demo, đọc chương 1–3, thử truy cập chương 4 bằng tài khoản thường rồi mở bằng tài khoản VIP còn hạn. Quay lại kệ sách để trình diễn **Đọc tiếp**.

Chuyển khoản trong buổi demo dùng QR mô phỏng và nhân viên xác nhận thủ công. Nếu demo chức năng đặt quá hạn, chạy tác vụ lịch Laravel hoặc dùng sẵn một yêu cầu hết hạn trong database demo.
