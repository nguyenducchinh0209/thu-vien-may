<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class DemoEpubSeeder extends Seeder
{
    private const PATH = 'book-epubs/demo/nhat-ky-thu-vien-may.epub';

    private const CHAPTERS = [
        ['Ngày cửa mở', 'Trời vừa tạnh mưa khi Linh đến mở cửa phòng đọc. Bên hiên có một vệt nước hẹp, đủ để soi ba chiếc ghế gỗ và mảng trời đang sáng dần. Cô lau sàn, sắp lại chồng phiếu mượn rồi đặt một chiếc hộp giấy lên bàn. Trên nắp hộp, cô viết: “Một câu chuyện bạn muốn đọc”.',
            'Người ghé đầu tiên là một cậu bé đi tìm truyện về loài chim. Cuốn cậu cần đã có người mượn. Linh chỉ cậu cách gửi yêu cầu đặt trước và ghi lại ngày hẹn. Trước khi về, cậu bỏ một tờ giấy vào hộp: “Em muốn biết chim làm gì khi trời mưa”.'],
        ['Chiếc thẻ đánh dấu', 'Buổi trưa, bác Tân trả một cuốn sách có chiếc thẻ cũ kẹp giữa hai trang. Bác định bỏ nó đi, nhưng Linh nhận ra mặt sau có mấy dòng ghi chú bằng bút chì. Đó là danh sách những loài cây từng mọc quanh bờ ao, cùng ngày bác nhìn thấy chúng lần đầu.',
            'Hai người tìm một tờ giấy mới và chép lại từng cái tên. Bác cười khi nhớ ra có một cây mình đã ghi hai lần. Bản gốc được trả vào tay bác, còn bản chép đặt trong hộp gợi ý để người đọc sau có thể đi tìm câu chuyện của những cái cây.'],
        ['Ba phút trước giờ đóng cửa', 'Khi còn ba phút nữa đến giờ đóng cửa, Mai chạy vào với chiếc ba lô mở khóa. Cô đã đọc xong một nửa, nhưng tuần sau mới quay lại được. Linh xem hạn trả cùng Mai và ghi lại lượt trả. Sau đó, cô chỉ cho Mai một truyện điện tử ngắn có thể mở trên điện thoại.',
            'Mai thử đọc chương đầu ngay ở bàn. Khi đứng dậy, cô nhớ được tên nhân vật nhưng quên mất mình dừng ở đoạn nào. Linh bảo cứ mở lại kệ sách vào tối mai. Ở đó có một dấu nhỏ ghi chương gần nhất mà Mai đã mở.'],
        ['Buổi đọc bên cửa sổ', 'Cuối tuần, nhóm đọc hẹn nhau cạnh cửa sổ. Mỗi người mang đến một câu hỏi thay cho bài giới thiệu. Cậu bé từng hỏi về chim mang theo cuốn sách đã nhận ở quầy. Cậu kể rằng nhiều loài thường tìm tán cây rậm để trú mưa, rồi hỏi mọi người đã từng nhìn thấy tổ chim ở đâu.',
            'Cả nhóm lần lượt kể về một hàng cây, một sân trường, một mái tôn có lỗ nhỏ. Linh ghi lại những nơi người ta có thể quan sát từ xa. Cô để trống một hàng cuối cùng, phòng khi tuần sau có ai mang thêm một địa chỉ mới.'],
        ['Một chỗ cho câu chuyện tiếp theo', 'Tháng sau, hộp giấy đã đầy. Linh phân những câu hỏi thành ba chồng: câu đã có sách, câu cần tìm thêm và câu muốn mang vào buổi đọc. Cô gọi cậu bé giúp mình dán nhãn. Cậu lấy một tờ giấy xanh và cẩn thận viết “Chưa biết” cho chồng thứ hai.',
            'Lúc đóng cửa, Linh thay tờ giấy trên hộp. Lần này cô viết: “Nếu chưa tìm thấy câu trả lời, hãy để lại câu hỏi”. Cô tắt đèn, giữ lại ngọn đèn nhỏ bên cửa sổ và nghĩ tới những người sẽ ghé qua vào ngày mai.'],
    ];

    public function run(): void
    {
        if (! extension_loaded('zip')) {
            throw new RuntimeException('Cần bật extension ZIP để tạo EPUB demo.');
        }

        $book = Book::withTrashed()->firstOrCreate(['book_code' => 'MAYEPUB001'], [
            'title' => 'Nhật ký Thư viện Mây',
            'category' => 'Truyện ngắn',
            'description' => 'Năm mẩu chuyện tiếng Việt viết cho dự án Thư viện Mây. Đọc thử ba chương đầu, đọc trọn năm chương với VIP.',
            'total_quantity' => 0,
            'rental_fee' => '0.00',
            'publisher' => 'Thư viện Mây',
        ]);
        if ($book->trashed() || ($book->epub_path && $book->epub_path !== self::PATH)) {
            return;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists(self::PATH) && ! $disk->put(self::PATH, $this->contents())) {
            throw new RuntimeException('Không lưu được EPUB demo.');
        }
        $author = Author::firstOrCreate(['name' => 'Thư viện Mây']);
        $book->authors()->syncWithoutDetaching([$author->id]);
        if ($book->epub_path !== self::PATH) {
            $book->forceFill(['epub_path' => self::PATH])->save();
        }
    }

    private function contents(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'may-epub-');
        if (! $temp) {
            throw new RuntimeException('Không tạo được tệp EPUB tạm.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Không tạo được gói EPUB.');
            }
            $zip->addFromString('mimetype', 'application/epub+zip');
            $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
            $zip->addFromString('META-INF/container.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
                .'<rootfile full-path="EPUB/package.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
            $manifest = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
            $spine = '';
            $toc = '';
            foreach (self::CHAPTERS as $index => $chapter) {
                $number = $index + 1;
                $name = 'chapter-'.$number.'.xhtml';
                $safeTitle = $this->xml($chapter[0]);
                $body = '<h1>'.$safeTitle.'</h1>';
                foreach (array_slice($chapter, 1) as $paragraph) {
                    $body .= '<p>'.$this->xml($paragraph).'</p>';
                }
                $zip->addFromString('EPUB/'.$name, $this->xhtml($safeTitle, $body));
                $manifest .= '<item id="ch'.$number.'" href="'.$name.'" media-type="application/xhtml+xml"/>';
                $spine .= '<itemref idref="ch'.$number.'"/>';
                $toc .= '<li><a href="'.$name.'">'.$safeTitle.'</a></li>';
            }
            $zip->addFromString('EPUB/nav.xhtml', $this->xhtml('Mục lục',
                '<nav epub:type="toc" id="toc"><h1>Mục lục</h1><ol>'.$toc.'</ol></nav>'));
            $zip->addFromString('EPUB/package.opf', '<?xml version="1.0" encoding="UTF-8"?>'
                .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="book-id">'
                .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
                .'<dc:identifier id="book-id">urn:uuid:1c2bf583-aac3-46f1-9ee5-c9086f9d912c</dc:identifier>'
                .'<dc:title>Nhật ký Thư viện Mây</dc:title><dc:language>vi</dc:language><dc:creator>Thư viện Mây</dc:creator>'
                .'<dc:rights>Nội dung gốc kèm đồ án, được cung cấp để dùng và chia sẻ bản demo.</dc:rights>'
                .'<meta property="dcterms:modified">2026-11-16T00:00:00Z</meta></metadata>'
                .'<manifest>'.$manifest.'</manifest><spine>'.$spine.'</spine></package>');
            if (! $zip->close()) {
                throw new RuntimeException('Không đóng được gói EPUB.');
            }

            return file_get_contents($temp);
        } finally {
            unlink($temp);
        }
    }

    private function xhtml(string $title, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="vi">'
            .'<head><title>'.$title.'</title></head><body>'.$body.'</body></html>';
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
