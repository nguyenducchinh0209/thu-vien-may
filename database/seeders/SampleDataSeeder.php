<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookCart;
use App\Models\BookCartItem;
use App\Models\BookComment;
use App\Models\BookReview;
use App\Models\BookSuggestion;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\LoanReturn;
use App\Models\LoanReturnItem;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SampleDataSeeder extends Seeder
{
    private const PREFIX = 'SAMPLE-2026';

    public function run(): void
    {
        $reader = User::query()->where('role', 'Độc giả')->orderBy('id')->first();
        $staff = User::query()->whereIn('role', ['Admin', 'Thủ thư'])->orderBy('id')->first();

        if (! $reader || ! $staff) {
            throw new RuntimeException('Sample data requires an existing active reader and staff account. No accounts were created.');
        }

        DB::transaction(function () use ($reader, $staff): void {
            $authors = $this->seedAuthors();
            $books = $this->seedBooks($authors);
            $this->seedLoans($reader, $staff, $books);
            $this->seedReservations($reader, $staff, $books);
            $this->seedCommunity($reader, $staff, $books);
            $this->seedCart($reader, $books);
        });
    }

    /** @return list<Author> */
    private function seedAuthors(): array
    {
        $rows = [
            ['Nguyễn An Bình', 'Tác giả hư cấu chuyên viết về văn hóa và đời sống Việt Nam.'],
            ['Trần Minh Châu', 'Tác giả hư cấu viết truyện cho độc giả trẻ.'],
            ['Lê Thu Phương', 'Tác giả hư cấu viết về khoa học và thiên nhiên.'],
            ['Phạm Quốc Huy', 'Tác giả hư cấu viết sách kỹ năng và lịch sử.'],
            ['Võ Hoài Nam', 'Tác giả hư cấu của các tập truyện ngắn.'],
        ];

        return collect($rows)->map(function (array $row, int $index): Author {
            $name = self::PREFIX.' - '.$row[0];
            $author = Author::withTrashed()->firstOrCreate(['name' => $name], ['biography' => $row[1]]);

            if ($author->trashed()) {
                throw new RuntimeException("Sample author {$name} was soft-deleted; refusing to restore it.");
            }

            return $author;
        })->all();
    }

    /** @param list<Author> $authors
     * @return array<string, Book>
     */
    private function seedBooks(array $authors): array
    {
        $rows = [
            ['001', 'Mùa gió qua hiên', 'Văn học Việt Nam', 'Một tập truyện hư cấu về những đổi thay nhỏ trong một xóm ven sông.', 6, 0],
            ['002', 'Bản đồ của những vì sao', 'Văn học thiếu nhi', 'Hành trình tưởng tượng của hai người bạn đi tìm một đài quan sát cũ.', 5, 1],
            ['003', 'Vườn cây trên mái nhà', 'Khoa học thường thức', 'Hướng dẫn hư cấu về quan sát cây xanh và làm vườn trong thành phố.', 4, 2],
            ['004', 'Dòng sông kể chuyện', 'Lịch sử', 'Những câu chuyện hư cấu gợi mở lịch sử các vùng đất bên sông.', 5, 3],
            ['005', 'Ngày đầu ở thư viện', 'Văn học thiếu nhi', 'Truyện hư cấu về một bạn nhỏ lần đầu tự chọn sách.', 7, 1],
            ['006', 'Những con đường của Hội An', 'Văn hóa', 'Tập tản văn hư cấu về kiến trúc, nghề thủ công và ký ức phố cổ.', 3, 0],
            ['007', 'Câu hỏi từ đại dương', 'Khoa học thường thức', 'Giới thiệu hư cấu về sinh vật biển và công việc nghiên cứu đại dương.', 4, 2],
            ['008', 'Lược sử những phát minh nhỏ', 'Lịch sử', 'Các mẩu chuyện hư cấu về dụng cụ quen thuộc trong đời sống.', 4, 3],
            ['009', 'Tiệm sách cuối con phố', 'Văn học Việt Nam', 'Truyện hư cấu về một hiệu sách cũ và những người ghé thăm.', 5, 4],
            ['010', 'Học cách lắng nghe', 'Kỹ năng sống', 'Những tình huống hư cấu giúp người đọc thực hành lắng nghe.', 6, 3],
            ['011', 'Mây trắng ở sân ga', 'Văn học thiếu nhi', 'Tập truyện hư cấu về những chuyến tàu và tình bạn.', 3, 1],
            ['012', 'Bếp nhỏ, mùa lớn', 'Đời sống', 'Câu chuyện hư cấu về món ăn gia đình và mùa vụ.', 4, 4],
            ['013', 'Nhật ký người quan sát chim', 'Thiên nhiên', 'Ghi chép hư cấu khơi gợi cách quan sát chim quanh nơi ở.', 5, 2],
            ['014', 'Một ngày không màn hình', 'Kỹ năng sống', 'Gợi ý hư cấu cho các hoạt động đọc, vẽ và khám phá ngoài trời.', 4, 3],
            ['015', 'Chuyện kể dưới tán bàng', 'Văn học Việt Nam', 'Tập truyện ngắn hư cấu về một sân trường qua nhiều năm.', 5, 4],
        ];
        $books = [];

        foreach ($rows as [$suffix, $title, $category, $description, $quantity, $authorIndex]) {
            $code = 'SMP26-'.$suffix;
            $book = Book::withTrashed()->firstOrCreate(['book_code' => $code], [
                'title' => $title,
                'category' => $category,
                'description' => $description,
                'publisher' => 'Nhà xuất bản Mẫu Việt',
                'publication_year' => 2020 + ((int) $suffix % 7),
                'edition' => 'Bản mẫu',
                'total_quantity' => $quantity,
                'rental_fee' => '5000.00',
                'preview_pages' => [],
            ]);

            if ($book->trashed()) {
                throw new RuntimeException("Sample book {$code} was soft-deleted; refusing to restore it.");
            }

            $book->authors()->syncWithoutDetaching([$authors[$authorIndex]->id]);
            $books[$suffix] = $book;
        }

        return $books;
    }

    /** @param array<string, Book> $books */
    private function seedLoans(User $reader, User $staff, array $books): void
    {
        $now = now();
        $fixtures = [
            ['ACTIVE', 'active', $now->copy()->subDay(), $now->copy()->addDays(13), null, [['001', 1], ['002', 1]]],
            ['PARTIAL', 'active', $now->copy()->subDays(25), $now->copy()->subDays(11), null, [['003', 2]]],
            ['RETURNED', 'returned', $now->copy()->subDays(60), $now->copy()->subDays(46), $now->copy()->subDays(40), [['004', 1]]],
            ['OVERDUE', 'active', $now->copy()->subDays(30), $now->copy()->subDays(16), null, [['005', 1]]],
        ];

        foreach ($fixtures as [$key, $status, $issuedAt, $dueAt, $returnedAt, $items]) {
            $receiptNote = self::PREFIX.' loan '.$key;
            $rentalTotal = collect($items)->sum(fn (array $item): int => $item[1] * 5000);
            $loan = Loan::query()->firstOrCreate(['rental_fee_receipt_note' => $receiptNote], [
                'reader_id' => $reader->id,
                'issued_by' => $staff->id,
                'issued_at' => $issuedAt,
                'due_at' => $dueAt,
                'returned_at' => $returnedAt,
                'status' => $status,
                'rental_fee_total' => $rentalTotal,
                'rental_fee_received' => $rentalTotal,
                'rental_fee_received_at' => $issuedAt,
                'rental_fee_received_by' => $staff->id,
                'rental_fee_payment_method' => 'cash',
            ]);

            if (! $loan->wasRecentlyCreated) {
                continue;
            }

            foreach ($items as [$bookCode, $quantity]) {
                LoanItem::create([
                    'loan_id' => $loan->id,
                    'book_id' => $books[$bookCode]->id,
                    'quantity' => $quantity,
                    'returned_quantity' => 0,
                    'rental_fee_per_copy' => '5000.00',
                ]);
            }

            if ($key === 'PARTIAL') {
                $item = LoanItem::query()->where('loan_id', $loan->id)->firstOrFail();
                $this->createReturn($loan, $staff, $item, 1, $dueAt->copy()->addDays(5), 'UNPAID');
            } elseif ($key === 'RETURNED') {
                $item = LoanItem::query()->where('loan_id', $loan->id)->firstOrFail();
                $this->createReturn($loan, $staff, $item, 1, $returnedAt, 'PAID');
            }
        }
    }

    private function createReturn(Loan $loan, User $staff, LoanItem $item, int $quantity, $returnedAt, string $fineState): void
    {
        $return = LoanReturn::query()->firstOrCreate([
            'loan_id' => $loan->id,
            'returned_by' => $staff->id,
            'returned_at' => $returnedAt,
        ]);
        $returnItem = LoanReturnItem::query()->firstOrCreate([
            'loan_return_id' => $return->id,
            'loan_item_id' => $item->id,
        ], ['quantity' => $quantity]);
        $item->update(['returned_quantity' => $quantity]);

        $lateDays = max(1, (int) $loan->due_at->copy()->startOfDay()->diffInDays($returnedAt->copy()->startOfDay()));
        $amount = $lateDays * 3000 * $quantity;
        $fine = Fine::query()->firstOrCreate(['loan_return_item_id' => $returnItem->id], [
            'loan_id' => $loan->id,
            'reader_id' => $loan->reader_id,
            'overdue_days' => $lateDays,
            'quantity' => $quantity,
            'daily_rate' => 3000,
            'amount' => $amount,
            'status' => $fineState === 'PAID' ? 'paid' : 'unpaid',
            'paid_by' => $fineState === 'PAID' ? $staff->id : null,
            'paid_at' => $fineState === 'PAID' ? $returnedAt : null,
        ]);

        if ($fineState === 'PAID') {
            $fine->payments()->firstOrCreate([
                'receipt_note' => self::PREFIX.' payment '.$fine->id,
            ], [
                'received_by' => $staff->id,
                'amount' => $fine->amount,
                'method' => 'cash',
                'paid_at' => $returnedAt,
            ]);
        }
    }

    /** @param array<string, Book> $books */
    private function seedReservations(User $reader, User $staff, array $books): void
    {
        Reservation::query()->firstOrCreate([
            'reader_id' => $reader->id,
            'book_id' => $books['006']->id,
        ], [
            'quantity' => 1,
            'status' => 'pending',
        ]);

        Reservation::query()->firstOrCreate([
            'reader_id' => $reader->id,
            'book_id' => $books['007']->id,
        ], [
            'approved_by' => $staff->id,
            'quantity' => 1,
            'status' => 'ready',
            'ready_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    /** @param array<string, Book> $books */
    private function seedCommunity(User $reader, User $staff, array $books): void
    {
        $reviews = [
            ['001', 5, 'Một buổi chiều yên', 'Giọng kể nhẹ nhàng, dễ đọc.'],
            ['002', 4, 'Nhiều ý tưởng thú vị', 'Phần hành trình được kể sinh động.'],
            ['003', 5, 'Gợi ý thực tế', 'Các chủ đề quan sát gần gũi với gia đình.'],
            ['004', 3, 'Dễ theo dõi', 'Một lựa chọn tham khảo cho người mới đọc lịch sử.'],
        ];

        foreach ($reviews as [$code, $rating, $title, $body]) {
            BookReview::query()->firstOrCreate([
                'book_id' => $books[$code]->id,
                'user_id' => $reader->id,
            ], compact('rating', 'title', 'body'));
        }

        foreach ([
            ['001', 'Mình thích không khí ấm áp của câu chuyện.'],
            ['002', 'Bản đồ trong truyện làm mình muốn tìm hiểu thiên văn.'],
            ['005', 'Một câu chuyện vui về lần đầu mượn sách.'],
            ['009', 'Hiệu sách trong truyện có nhiều nhân vật đáng nhớ.'],
            ['013', 'Chủ đề quan sát thiên nhiên rất thư thái.'],
        ] as [$code, $body]) {
            BookComment::query()->firstOrCreate([
                'book_id' => $books[$code]->id,
                'user_id' => $reader->id,
                'body' => self::PREFIX.' '.$body,
            ]);
        }

        foreach ([
            ['pending', 'Bản đồ các chòm sao Việt Nam', null],
            ['approved', 'Những cây thuốc quanh ta', 'Đề xuất được đưa vào danh sách xem xét.'],
            ['rejected', 'Truyện kể về những chiếc thuyền giấy', 'Đã có đầu sách tương tự trong danh mục.'],
            ['ordered', 'Atlas thiên nhiên Đông Nam Á', 'Đã đặt mua bản mẫu.'],
        ] as [$status, $title, $staffNote]) {
            $reviewed = $status === 'pending' ? [] : [
                'reviewed_by' => $staff->id,
                'staff_note' => $staffNote,
                'reviewed_at' => now(),
            ];
            BookSuggestion::query()->firstOrCreate([
                'user_id' => $reader->id,
                'title' => self::PREFIX.' '.$title,
            ], array_merge([
                'author_name' => 'Tác giả mẫu',
                'publisher' => 'Nhà xuất bản Mẫu Việt',
                'reason' => 'Dữ liệu hư cấu phục vụ minh họa giao diện.',
                'status' => $status,
            ], $reviewed));
        }
    }

    /** @param array<string, Book> $books */
    private function seedCart(User $reader, array $books): void
    {
        $cart = BookCart::query()->firstOrCreate(['user_id' => $reader->id]);

        foreach ([['008', 1], ['010', 2], ['011', 1]] as [$code, $quantity]) {
            BookCartItem::query()->firstOrCreate([
                'book_cart_id' => $cart->id,
                'book_id' => $books[$code]->id,
            ], ['quantity' => $quantity]);
        }
    }
}
