<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthorReaderCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_search_update_and_soft_delete_an_author(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Thủ thư']));

        $this->postJson('/staff/authors', [
            'name' => 'Nguyen Van A',
            'biography' => 'Author biography.',
        ])
            ->assertCreated()
            ->assertJsonPath('author.name', 'Nguyen Van A');

        $author = Author::where('name', 'Nguyen Van A')->firstOrFail();
        $this->getJson('/staff/authors?q=Nguyen')
            ->assertOk()
            ->assertJsonPath('data.0.id', $author->id);

        $this->patchJson("/staff/authors/{$author->id}", ['name' => 'Updated Author'])
            ->assertOk()
            ->assertJsonPath('author.name', 'Updated Author');

        $this->deleteJson("/staff/authors/{$author->id}")
            ->assertOk();

        $this->assertSoftDeleted('authors', ['id' => $author->id]);
    }

    public function test_staff_can_create_update_and_soft_delete_a_reader(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'Admin']));

        $this->postJson('/staff/readers', [
            'name' => 'Example Reader',
            'email' => 'READER@example.test',
            'password' => 'reader-password',
            'password_confirmation' => 'reader-password',
            'phone' => '0900000000',
            'address' => 'Ha Noi',
            'date_of_birth' => '2000-01-01',
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            'role' => 'Admin',
        ])
            ->assertCreated()
            ->assertJsonPath('reader.role', 'Độc giả')
            ->assertJsonPath('reader.reader_profile.phone', '0900000000');

        $reader = User::where('email', 'reader@example.test')->firstOrFail();
        $profile = $reader->readerProfile;
        $this->assertNotNull($profile);
        $this->assertSame('RD'.str_pad((string) $reader->id, 6, '0', STR_PAD_LEFT), $profile->reader_code);
        $this->assertTrue(Hash::check('reader-password', $reader->password));
        Storage::disk('public')->assertExists($profile->avatar_path);

        $this->getJson('/staff/readers?q=0900000000')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reader->id);

        $this->patchJson("/staff/readers/{$reader->id}", [
            'name' => 'Updated Reader',
            'phone' => '0911111111',
            'role' => 'Admin',
            'password' => 'another-password',
        ])
            ->assertOk()
            ->assertJsonPath('reader.name', 'Updated Reader')
            ->assertJsonPath('reader.reader_profile.phone', '0911111111')
            ->assertJsonPath('reader.role', 'Độc giả');

        $reader->refresh();
        $this->assertTrue(Hash::check('reader-password', $reader->password));

        $this->deleteJson("/staff/readers/{$reader->id}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $reader->id]);
        $this->assertDatabaseHas('reader_profiles', ['id' => $profile->id]);
    }

    public function test_reader_creation_validation_and_permissions_are_enforced(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Độc giả']));

        $this->getJson('/staff/authors')->assertForbidden();
        $this->postJson('/staff/readers', [
            'name' => 'X',
            'email' => 'not-an-email',
        ])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'Admin']));
        $this->postJson('/staff/readers', [
            'name' => 'X',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_only_reader_accounts_are_returned_by_reader_endpoints(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Admin']));
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $librarian = User::factory()->create(['role' => 'Thủ thư']);

        $this->getJson('/staff/readers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reader->id)
            ->assertJsonMissing(['id' => $librarian->id]);

        $this->getJson("/staff/readers/{$librarian->id}")->assertNotFound();
    }
}
