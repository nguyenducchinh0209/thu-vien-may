<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\DemoEpubSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffSearchAndDemoEpubTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_find_a_loan_older_than_fifty_records_and_filter_the_results(): void
    {
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $old = null;
        for ($index = 0; $index < 57; $index++) {
            $loan = Loan::create([
                'reader_id' => $reader->id, 'issued_by' => $staff->id,
                'issued_at' => now()->subDays(70 - $index), 'due_at' => now()->subDays(1),
                'status' => $index === 0 ? 'active' : 'returned',
            ]);
            $old ??= $loan;
        }

        $this->actingAs($staff)->getJson('/staff/loans?page=8&per_page=8&summary=1')
            ->assertOk()->assertJsonPath('total', 57)->assertJsonPath('data.0.id', $old->id);
        $this->getJson('/staff/loans?q=%23'.$old->id.'&status=overdue')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $old->id);
        $this->actingAs($reader)->getJson('/staff/loans?q=%23'.$old->id)->assertForbidden();
    }

    public function test_original_demo_epub_has_distinct_preview_and_vip_chapters_and_saves_the_reader_position(): void
    {
        Storage::fake('local');
        $this->seed(DemoEpubSeeder::class);
        $this->seed(DemoEpubSeeder::class);
        $this->assertDatabaseCount('books', 1);
        $book = Book::where('book_code', 'MAYEPUB001')->firstOrFail();
        Storage::disk('local')->assertExists($book->epub_path);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $path = '/books/'.$book->id.'/read';

        $this->actingAs($reader)->get($path.'?chapter=3')->assertOk()->assertSee('Ba phút trước giờ đóng cửa');
        $this->get($path.'?chapter=4')->assertForbidden();
        $this->getJson('/my/reading-books')->assertOk()->assertJsonPath('data.0.last_chapter', 3);
        Membership::create(['user_id'=>$reader->id, 'tier'=>'vip', 'status'=>'active', 'starts_at'=>now(), 'expires_at'=>now()->addDays(2)]);
        $this->get($path.'?chapter=5')->assertOk()->assertSee('Một chỗ cho câu chuyện tiếp theo');
        $this->getJson('/my/reading-books')->assertOk()->assertJsonPath('data.0.last_chapter', 5);
    }
}
