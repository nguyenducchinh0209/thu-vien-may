<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class OnlineReadingTest extends TestCase
{
    use RefreshDatabase;

    private array $epubFixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('Enable PHP zip extension to run EPUB feature tests.');
        }
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->epubFixtures as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_free_reader_sees_only_three_chapters_and_cannot_request_the_next_one(): void
    {
        $book = $this->bookWithEpub();
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 3]))
            ->assertOk()
            ->assertSee('Chapter 3 text')
            ->assertDontSee('Chapter 4 title');

        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 4]))
            ->assertForbidden();
    }

    public function test_active_vip_can_read_all_chapters_and_use_correct_navigation_links(): void
    {
        $book = $this->bookWithEpub();
        $reader = User::factory()->create(['role' => 'Độc giả']);
        Membership::query()->create([
            'user_id' => $reader->id,
            'tier' => 'vip',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 4]))
            ->assertOk()
            ->assertSee('Chapter 4 text')
            ->assertSee(route('books.read', ['book' => $book, 'chapter' => 3]), false)
            ->assertDontSee('Next chapter');
    }

    public function test_expired_vip_is_limited_to_the_free_preview(): void
    {
        $book = $this->bookWithEpub();
        $reader = User::factory()->create(['role' => 'Độc giả']);
        Membership::query()->create([
            'user_id' => $reader->id,
            'tier' => 'vip',
            'status' => 'active',
            'starts_at' => now()->subMonths(2),
            'expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 3]))->assertOk();
        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 4]))->assertForbidden();
    }

    public function test_free_preview_fails_closed_for_multiple_toc_chapters_inside_one_xhtml_document(): void
    {
        $book = $this->bookWithEpub(singleDocument: true);
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 1]))
            ->assertForbidden()
            ->assertDontSee('Anchor chapter 1 text')
            ->assertDontSee('Anchor chapter 4 text');

        Membership::query()->create([
            'user_id' => $reader->id,
            'tier' => 'vip',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 1]))
            ->assertOk()
            ->assertSee('Anchor chapter 1 text')
            ->assertSee('Anchor chapter 4 text');
    }

    public function test_free_preview_fails_closed_without_chapter_navigation_but_vip_can_read(): void
    {
        $book = $this->bookWithEpub(singleDocument: true, includeNavigation: false);
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->get(route('books.read', $book))
            ->assertForbidden()
            ->assertDontSee('Anchor chapter 1 text');

        Membership::query()->create([
            'user_id' => $reader->id,
            'tier' => 'vip',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($reader)->get(route('books.read', $book))
            ->assertOk()
            ->assertSee('Anchor chapter 1 text')
            ->assertSee('Anchor chapter 4 text');
    }

    public function test_ambiguous_epub3_toc_cannot_fall_back_to_a_coarser_ncx(): void
    {
        $book = $this->bookWithEpub(singleDocument: true, includeLegacyNcx: true);
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->get(route('books.read', $book))
            ->assertForbidden()
            ->assertDontSee('Anchor chapter 1 text')
            ->assertDontSee('Anchor chapter 4 text');

        Membership::query()->create([
            'user_id' => $reader->id,
            'tier' => 'vip',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($reader)->get(route('books.read', $book))
            ->assertOk()
            ->assertSee('Anchor chapter 1 text')
            ->assertSee('Anchor chapter 4 text');
    }

    public function test_valid_ncx_is_used_when_epub3_navigation_is_absent(): void
    {
        $book = $this->bookWithEpub(includeNavigation: false, includeLegacyNcx: true);
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 3]))
            ->assertOk()
            ->assertSee('Chapter 3 text')
            ->assertDontSee('Chapter 4 text');
        $this->actingAs($reader)->get(route('books.read', ['book' => $book, 'chapter' => 4]))
            ->assertForbidden();
    }

    public function test_staff_can_upload_an_epub_to_private_storage_without_exposing_its_path(): void
    {
        $book = $this->book();
        $staff = User::factory()->create(['role' => 'Thủ thư']);
        $epubPath = $this->createEpub();

        $this->actingAs($staff)->post(route('staff.books.epub.store', $book), [
            'epub' => new UploadedFile($epubPath, 'sample.epub', 'application/epub+zip', null, true),
        ])->assertRedirect(route('books.read', ['book' => $book, 'chapter' => 1]));

        $book->refresh();
        $this->assertNotNull($book->epub_path);
        Storage::disk('local')->assertExists($book->epub_path);
        Storage::disk('public')->assertMissing($book->epub_path);
        $this->actingAs(User::factory()->create(['role' => 'Độc giả']))
            ->get(route('books.read', ['book' => $book]))
            ->assertOk()
            ->assertDontSee($book->epub_path);
        $this->get(route('catalog.books.show', $book))
            ->assertOk()
            ->assertDontSee($book->epub_path)
            ->assertJsonPath('book.has_epub', true)
            ->assertJsonMissingPath('book.epub_path');
    }

    public function test_upload_rejects_zip_paths_that_escape_the_archive(): void
    {
        $book = $this->book();
        $staff = User::factory()->create(['role' => 'Admin']);
        $epubPath = $this->createEpub(['../outside.xhtml' => '<html><body>Unsafe</body></html>']);

        $this->actingAs($staff)->post(route('staff.books.epub.store', $book), [
            'epub' => new UploadedFile($epubPath, 'unsafe.epub', 'application/epub+zip', null, true),
        ])->assertUnprocessable();

        $this->assertNull($book->fresh()->epub_path);
    }

    public function test_upload_rejects_repeated_spine_entries(): void
    {
        $book = $this->book();
        $staff = User::factory()->create(['role' => 'Admin']);
        $epubPath = $this->createEpub([], 10);

        $this->actingAs($staff)->post(route('staff.books.epub.store', $book), [
            'epub' => new UploadedFile($epubPath, 'repeated.epub', 'application/epub+zip', null, true),
        ])->assertUnprocessable();
    }

    public function test_chapter_markup_and_scripts_are_rendered_as_safe_text(): void
    {
        $book = $this->bookWithEpub(xss: true);
        $reader = User::factory()->create(['role' => 'Độc giả']);

        $this->actingAs($reader)->get(route('books.read', $book))
            ->assertOk()
            ->assertDontSee('window.pwned')
            ->assertDontSee('onerror')
            ->assertDontSee('<img');
    }

    private function bookWithEpub(bool $xss = false, bool $singleDocument = false, bool $includeNavigation = true, bool $includeLegacyNcx = false): Book
    {
        $book = $this->book();
        $path = $this->createEpub([], 0, $xss, $singleDocument, $includeNavigation, $includeLegacyNcx);
        $storedPath = 'book-epubs/'.$book->id.'/fixture.epub';
        Storage::disk('local')->put($storedPath, file_get_contents($path));
        $book->forceFill(['epub_path' => $storedPath])->save();

        return $book;
    }

    private function book(): Book
    {
        return Book::query()->create([
            'book_code' => 'BK'.fake()->unique()->numerify('######'),
            'title' => 'Sample Book',
            'total_quantity' => 1,
            'rental_fee' => 0,
        ]);
    }

    /** @param array<string, string> $extraEntries */
    private function createEpub(array $extraEntries = [], int $spineRepeats = 0, bool $xss = false, bool $singleDocument = false, bool $includeNavigation = true, bool $includeLegacyNcx = false): string
    {
        $directory = base_path('storage/app/private/epub-test-fixtures');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.'epub-'.Str::uuid().'.epub';
        $this->epubFixtures[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container><rootfiles><rootfile full-path="OPS/book.opf"/></rootfiles></container>');

        $manifest = '';
        $spine = '';
        $tocLinks = '';
        $ncxPoints = '';
        if ($singleDocument) {
            $manifest .= '<item id="c1" href="chapter1.xhtml" media-type="application/xhtml+xml"/>';
            $spine .= '<itemref idref="c1"/>';
            $ncxPoints .= '<navPoint id="n1" playOrder="1"><navLabel><text>Whole book</text></navLabel><content src="chapter1.xhtml"/></navPoint>';
            $body = '';
            for ($chapter = 1; $chapter <= 4; $chapter++) {
                $body .= '<h1 id="anchor'.$chapter.'">Anchor chapter '.$chapter.' title</h1><p>Anchor chapter '.$chapter.' text</p>';
                $tocLinks .= '<li><a href="chapter1.xhtml#anchor'.$chapter.'">Anchor chapter '.$chapter.'</a></li>';
            }
            $zip->addFromString('OPS/chapter1.xhtml', '<html><head><title>Single document</title></head><body>'.$body.'</body></html>');
        } else {
            for ($chapter = 1; $chapter <= 4; $chapter++) {
                $manifest .= '<item id="c'.$chapter.'" href="chapter'.$chapter.'.xhtml" media-type="application/xhtml+xml"/>';
                $spine .= '<itemref idref="c'.$chapter.'"/>';
                $tocLinks .= '<li><a href="chapter'.$chapter.'.xhtml">Chapter '.$chapter.'</a></li>';
                $ncxPoints .= '<navPoint id="n'.$chapter.'" playOrder="'.$chapter.'"><navLabel><text>Chapter '.$chapter.'</text></navLabel><content src="chapter'.$chapter.'.xhtml"/></navPoint>';
                $unsafeMarkup = $xss && $chapter === 1 ? '<script>window.pwned=true</script><img src=x onerror="window.pwned=true">' : '';
                $zip->addFromString('OPS/chapter'.$chapter.'.xhtml', '<html><head><title>Chapter '.$chapter.' title</title></head><body><h1>Chapter '.$chapter.' title</h1><p>Chapter '.$chapter.' text</p>'.$unsafeMarkup.'</body></html>');
            }
        }
        if ($includeNavigation) {
            $manifest .= '<item id="nav" href="toc.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
            $zip->addFromString('OPS/toc.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><body><nav epub:type="toc"><ol>'.$tocLinks.'</ol></nav><nav epub:type="landmarks"><ol><li><a href="chapter1.xhtml">Start</a></li></ol></nav></body></html>');
        }
        $spineToc = '';
        if ($includeLegacyNcx) {
            $manifest .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
            $spineToc = ' toc="ncx"';
            $zip->addFromString('OPS/toc.ncx', '<?xml version="1.0"?><ncx><navMap>'.$ncxPoints.'</navMap></ncx>');
        }
        for ($repeat = 0; $repeat < $spineRepeats; $repeat++) {
            $spine .= '<itemref idref="c1"/>';
        }
        $zip->addFromString('OPS/book.opf', '<?xml version="1.0"?><package><manifest>'.$manifest.'</manifest><spine'.$spineToc.'>'.$spine.'</spine></package>');
        foreach ($extraEntries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return $path;
    }
}
