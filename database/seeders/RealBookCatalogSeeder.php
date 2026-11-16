<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RealBookCatalogSeeder extends Seeder
{
    /** Add verified Vietnamese editions without touching unrelated catalog rows. */
    public function run(): void
    {
        $books = [
            ['Mắt biếc', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam', '9786041140783', 'NXB Trẻ', 2019, 'Tái bản 2019', 4500, 'Câu chuyện về Ngạn và Hà Lan, tình bạn từ làng Đo Đo và một tình yêu đơn phương kéo dài qua nhiều năm.'],
            ['Con chim xanh biếc bay về', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam', '9786041174474', 'NXB Trẻ', 2020, 'Tái bản', 7000, 'Một câu chuyện về những người trẻ, tình yêu và các lựa chọn trong cuộc sống hiện đại, kể bằng giọng văn trong trẻo quen thuộc của Nguyễn Nhật Ánh.'],
            ['Tôi thấy hoa vàng trên cỏ xanh', 'Nguyễn Nhật Ánh', 'Văn học Việt Nam', '9786041218543', 'NXB Trẻ', 2023, 'Tái bản', 3500, 'Thiều kể về tuổi thơ ở một làng quê miền Trung, về em trai Tường, bạn bè, những trò nghịch ngợm và các rung động đầu đời.'],
            ['Dế Mèn phiêu lưu ký', 'Tô Hoài', 'Văn học thiếu nhi', '9786042353397', 'NXB Kim Đồng', 2024, 'Tái bản', 8000, 'Dế Mèn lên đường khám phá thế giới, gặp nhiều loài vật và học về tình bạn, lòng dũng cảm, trách nhiệm cùng cách sống hòa hợp.'],
            ['Tuổi trẻ đáng giá bao nhiêu?', 'Rosie Nguyễn', 'Kỹ năng sống', '9786045370193', 'NXB Hội Nhà Văn', 2017, 'Tái bản', 5500, 'Tác giả chia sẻ trải nghiệm về học tập, đọc sách, đi và làm việc, khuyến khích người trẻ chủ động xây dựng cuộc sống mình mong muốn.'],
            ['Cà phê cùng Tony', 'Tony Buổi Sáng', 'Kỹ năng sống', '9786045958148', 'NXB Lao Động', 2015, 'Tái bản', 9000, 'Tập hợp những bài viết ngắn, hài hước về thái độ làm việc, cách ứng xử và tinh thần tự học trong cuộc sống.'],
            ['Số đỏ', 'Vũ Trọng Phụng', 'Văn học Việt Nam', '9786043494891', 'NXB Văn Học', 2022, 'Tái bản', 6000, 'Tiểu thuyết trào phúng theo chân Xuân Tóc Đỏ, dùng những tình huống lố bịch để châm biếm thói trưởng giả và sự giả dối trong xã hội đương thời.'],
            ['Đắc nhân tâm', 'Dale Carnegie', 'Kỹ năng sống', '9786043516609', 'NXB Hồng Đức', 2022, 'Bản dịch Nguyễn Hiến Lê', 4000, 'Cuốn sách trình bày các nguyên tắc giao tiếp và ứng xử như lắng nghe, tôn trọng người khác và góp ý một cách chân thành.'],
            ['Nhà giả kim', 'Paulo Coelho', 'Văn học nước ngoài', '9786043478471', 'NXB Hội Nhà Văn', 2023, 'Bản dịch Lê Châu Cầu', 7500, 'Santiago rời quê hương để tìm kho báu trong giấc mơ; chuyến đi qua sa mạc trở thành hành trình khám phá bản thân và điều mình theo đuổi.'],
            ['Cây cam ngọt của tôi', 'José Mauro de Vasconcelos', 'Văn học nước ngoài', '9786043332476', 'NXB Hội Nhà Văn', 2022, 'Bản dịch Nguyễn Bích Lan', 5000, 'Zeze, cậu bé giàu trí tưởng tượng, tìm thấy một người bạn tâm tình nơi cây cam nhỏ trong khu vườn và học cách đối diện với những đổi thay.'],
        ];

        DB::transaction(function () use ($books): void {
            foreach ($books as $index => [$title, $authorName, $category, $isbn, $publisher, $year, $edition, $fee, $description]) {
                $code = 'BKREAL'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                $book = Book::withTrashed()
                    ->where('isbn', $isbn)
                    ->orWhere('book_code', $code)
                    ->first();
                if ($book) {
                    continue;
                }

                $detailExtension = $index === 0 ? 'webp' : 'jpg';
                $cover = 'book-covers/'.$code.'-v2.png';
                $previews = ['book-previews/'.$code.'-detail.'.$detailExtension];
                foreach (['02', '03'] as $page) {
                    $preview = 'book-previews/'.$code.'-test-page-'.$page.'.png';
                    if (Storage::disk('local')->exists($preview)) {
                        $previews[] = $preview;
                    }
                }

                $author = Author::query()->firstOrCreate(['name' => $authorName]);
                $book = Book::create([
                    'book_code' => $code,
                    'title' => $title,
                    'category' => $category,
                    'isbn' => $isbn,
                    'description' => $description,
                    'publisher' => $publisher,
                    'publication_year' => $year,
                    'edition' => $edition,
                    'cover_image_path' => Storage::disk('public')->exists($cover) ? $cover : 'book-covers/'.$code.'.jpg',
                    'total_quantity' => 5,
                    'rental_fee' => $fee,
                    'preview_pages' => $previews,
                ]);

                $book->authors()->syncWithoutDetaching([$author->id]);
            }
        });
    }
}
