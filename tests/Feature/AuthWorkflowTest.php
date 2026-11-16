<?php

namespace Tests\Feature;

use App\Models\ReaderProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_accounts_cannot_log_in_and_a_successful_session_can_log_out(): void
    {
        $locked = User::factory()->create(['email' => 'locked@example.test', 'password' => 'old-password', 'is_locked' => true]);
        $this->postJson('/auth/login', ['email' => $locked->email, 'password' => 'old-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $reader = User::factory()->create(['email' => 'reader@example.test', 'password' => 'reader-password']);
        $this->postJson('/auth/login', ['email' => $reader->email, 'password' => 'reader-password', 'remember' => true])
            ->assertOk();
        $this->getJson('/auth/session')->assertOk()->assertJsonPath('user.id', $reader->id);
        $this->postJson('/auth/logout')->assertOk();
        $this->getJson('/auth/session')->assertUnauthorized();
    }

    public function test_admin_role_conversion_and_legacy_profile_update_create_reader_codes(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $staff = User::factory()->create(['role' => 'Thủ thư']);

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Độc giả'])
            ->assertOk();
        $staff->refresh();
        $this->assertSame('Độc giả', $staff->role);
        $this->assertSame('RD'.str_pad((string) $staff->id, 6, '0', STR_PAD_LEFT), $staff->readerProfile->reader_code);

        ReaderProfile::where('user_id', $staff->id)->delete();
        $this->actingAs($staff)->patchJson('/my/profile', [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => '0900000000',
            'role' => 'Admin',
        ])->assertOk()->assertJsonPath('profile.phone', '0900000000');

        $this->assertSame('Độc giả', $staff->fresh()->role);
        $this->assertSame('RD'.str_pad((string) $staff->id, 6, '0', STR_PAD_LEFT), $staff->fresh()->readerProfile->reader_code);
    }

    public function test_admin_and_librarian_can_update_their_own_profile_without_reader_identity(): void
    {
        foreach (['Admin', 'Thủ thư'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->getJson('/my/profile')
                ->assertOk()
                ->assertJsonPath('profile.phone', null);
            $this->getJson('/my/cart')->assertForbidden();

            $this->actingAs($user)->patchJson('/my/profile', [
                'name' => $user->name.' Updated',
                'email' => $user->email,
                'phone' => '0901234567',
                'address' => '123 Library Street',
                'date_of_birth' => '1990-02-03',
                'role' => 'Độc giả',
            ])->assertOk()
                ->assertJsonPath('profile.phone', '0901234567')
                ->assertJsonPath('profile.address', '123 Library Street')
                ->assertJsonPath('profile.date_of_birth', '1990-02-03')
                ->assertJsonPath('user.role', $role);

            $this->assertSame($role, $user->fresh()->role);
            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'phone' => '0901234567',
                'address' => '123 Library Street',
            ]);
            $this->assertSame('1990-02-03', $user->fresh()->date_of_birth->format('Y-m-d'));
            $this->assertDatabaseMissing('reader_profiles', ['user_id' => $user->id]);
        }
    }

    public function test_profile_avatar_is_uploaded_for_both_readers_and_staff(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'Admin']);
        $this->actingAs($admin)->post('/my/profile', [
            '_method' => 'PATCH',
            'name' => $admin->name,
            'email' => $admin->email,
            'avatar' => UploadedFile::fake()->image('admin-avatar.png'),
        ])->assertOk()->assertJsonPath('user.role', 'Admin');

        $admin->refresh();
        $this->assertNotNull($admin->avatar_path);
        Storage::disk('public')->assertExists($admin->avatar_path);
        $this->assertDatabaseMissing('reader_profiles', ['user_id' => $admin->id]);

        $reader = User::factory()->create(['role' => 'Độc giả']);
        $this->actingAs($reader)->post('/my/profile', [
            '_method' => 'PATCH',
            'name' => $reader->name,
            'email' => $reader->email,
            'avatar' => UploadedFile::fake()->image('reader-avatar.png'),
        ])->assertOk()->assertJsonPath('user.role', 'Độc giả');

        $this->assertNotNull($reader->fresh()->readerProfile->avatar_path);
        Storage::disk('public')->assertExists($reader->fresh()->readerProfile->avatar_path);
        $this->assertSame('RD'.str_pad((string) $reader->id, 6, '0', STR_PAD_LEFT), $reader->fresh()->readerProfile->reader_code);
    }

    public function test_profile_metadata_moves_with_account_role_changes(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $staff = User::factory()->create([
            'role' => 'Thủ thư',
            'phone' => '0901111111',
            'address' => 'Staff address',
            'date_of_birth' => '1988-04-05',
            'avatar_path' => 'staff-avatars/original.png',
        ]);

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Độc giả'])
            ->assertOk();
        $this->assertSame('0901111111', $staff->fresh()->readerProfile->phone);
        $this->assertSame('Staff address', $staff->fresh()->readerProfile->address);
        $this->assertSame('1988-04-05', $staff->fresh()->readerProfile->date_of_birth->format('Y-m-d'));
        $this->assertSame('staff-avatars/original.png', $staff->fresh()->readerProfile->avatar_path);

        $this->actingAs($staff->fresh())->patchJson('/my/profile', [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => '0902222222',
            'address' => 'Updated reader address',
            'date_of_birth' => '1989-05-06',
        ])->assertOk();
        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Thủ thư'])
            ->assertOk();

        $staff->refresh();
        $this->assertSame('0902222222', $staff->phone);
        $this->assertSame('Updated reader address', $staff->address);
        $this->assertSame('1989-05-06', $staff->date_of_birth->format('Y-m-d'));
        $this->assertSame('staff-avatars/original.png', $staff->avatar_path);
        $this->assertSame('RD'.str_pad((string) $staff->id, 6, '0', STR_PAD_LEFT), $staff->readerProfile->reader_code);

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Độc giả'])
            ->assertOk();
        $this->actingAs($staff->fresh())->patchJson('/my/profile', [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => null,
            'address' => null,
            'date_of_birth' => null,
        ])->assertOk();
        $staff->readerProfile()->update(['avatar_path' => null]);
        $this->assertNull($staff->fresh()->readerProfile->avatar_path);

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Thủ thư'])
            ->assertOk();
        $staff->refresh();
        $this->assertNull($staff->phone);
        $this->assertNull($staff->address);
        $this->assertNull($staff->date_of_birth);
        $this->assertNull($staff->avatar_path);

        $this->actingAs($admin)->patchJson("/staff/accounts/{$staff->id}", ['role' => 'Độc giả'])
            ->assertOk();
        $this->assertNull($staff->fresh()->readerProfile->phone);
        $this->assertNull($staff->fresh()->readerProfile->address);
        $this->assertNull($staff->fresh()->readerProfile->date_of_birth);
        $this->assertNull($staff->fresh()->readerProfile->avatar_path);
    }

    public function test_password_reset_is_neutral_one_time_and_revokes_the_current_session(): void
    {
        Notification::fake();
        $reader = User::factory()->create(['email' => 'reader@example.test', 'password' => 'old-password']);

        $known = $this->postJson('/auth/forgot-password', ['email' => $reader->email])->assertOk()->json();
        $unknown = $this->postJson('/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($known['message'], $unknown['message']);
        $this->assertArrayNotHasKey('token', $known);
        Notification::assertSentTo($reader, ResetPassword::class);

        $this->postJson('/auth/login', ['email' => $reader->email, 'password' => 'old-password', 'remember' => true])->assertOk();
        $this->getJson('/auth/session')->assertOk();
        $oldRememberToken = $reader->fresh()->remember_token;
        $token = Password::createToken($reader->fresh());
        $this->get(route('password.reset', ['token' => $token, 'email' => $reader->email]))
            ->assertOk()
            ->assertSee('name="token"', false)
            ->assertSee($token);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $reader->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect('/#login');
        $reader->refresh();
        $this->assertTrue(Hash::check('new-password', $reader->password));
        $this->assertNotSame($oldRememberToken, $reader->remember_token);
        $this->assertSame(1, $reader->auth_version);
        Auth::forgetGuards();
        $this->getJson('/auth/session')->assertUnauthorized();

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $reader->email,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('new-password', $reader->fresh()->password));
    }

    public function test_admin_password_reset_invalidates_an_existing_reader_session(): void
    {
        $reader = User::factory()->create(['email' => 'reader@example.test', 'password' => 'old-password']);
        $admin = User::factory()->create(['role' => 'Admin']);
        $this->postJson('/auth/login', ['email' => $reader->email, 'password' => 'old-password'])->assertOk();
        $this->getJson('/auth/session')->assertOk();
        $rememberToken = $reader->fresh()->remember_token;

        $this->actingAs($admin)->patchJson("/staff/accounts/{$reader->id}", ['password' => 'new-password'])->assertOk();
        $reader->refresh();
        $this->assertTrue(Hash::check('new-password', $reader->password));
        $this->assertNotSame($rememberToken, $reader->remember_token);
        $this->assertSame(1, $reader->auth_version);

        Auth::forgetGuards();
        $this->getJson('/auth/session')->assertUnauthorized();
    }

    public function test_legacy_session_without_a_version_is_rejected(): void
    {
        $reader = User::factory()->create(['email' => 'reader@example.test', 'password' => 'old-password']);
        $this->postJson('/auth/login', ['email' => $reader->email, 'password' => 'old-password'])->assertOk();
        session()->forget('auth_version_web');
        Auth::forgetGuards();
        $this->getJson('/auth/session')->assertUnauthorized();
    }

    public function test_valid_remember_cookie_can_establish_a_fresh_versioned_session(): void
    {
        $rememberedReader = User::factory()->create(['email' => 'remembered@example.test', 'password' => 'remember-password']);
        $response = $this->postJson('/auth/login', [
            'email' => $rememberedReader->email,
            'password' => 'remember-password',
            'remember' => true,
        ])->assertOk();
        $recaller = Auth::guard('web')->getRecallerName();
        $recallerCookie = $response->getCookie($recaller);
        $this->assertNotNull($recallerCookie);

        $this->flushSession();
        Auth::forgetGuards();
        $rememberedResponse = $this->withCookie($recaller, $recallerCookie->getValue())
            ->withCredentials()
            ->getJson('/auth/session');
        $this->assertSame(200, $rememberedResponse->status(), $rememberedResponse->getContent());
        $rememberedResponse->assertJsonPath('user.id', $rememberedReader->id);
        $this->assertSame($rememberedReader->fresh()->auth_version, session('auth_version_web'));
    }
}
