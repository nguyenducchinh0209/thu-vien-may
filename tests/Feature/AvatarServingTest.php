<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarServingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_avatar_url_is_same_origin_and_uploaded_file_is_stored_for_readers_and_staff(): void
    {
        Storage::fake('public');
        config(['filesystems.disks.public.url' => 'http://localhost:8000/storage']);
        $reader = User::factory()->create(['role' => 'Độc giả']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);

        foreach ([$reader, $staff] as $user) {
            $response = $this->actingAs($user)->post('/my/profile', [
                '_method' => 'PATCH',
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('profile.png'),
            ])->assertOk();

            $avatarUrl = $response->json('profile.avatar_url');
            $this->assertIsString($avatarUrl);
            $this->assertStringStartsWith('/storage/', $avatarUrl);
            $this->assertStringNotContainsString('localhost:8000', $avatarUrl);

            $user->refresh();
            $avatarPath = $user->role === 'Độc giả'
                ? $user->readerProfile->avatar_path
                : $user->avatar_path;
            $this->assertNotEmpty($avatarPath);
            Storage::disk('public')->assertExists($avatarPath);
        }
    }
}
