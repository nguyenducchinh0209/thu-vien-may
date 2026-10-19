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
        ])->assertOk()->assertJsonPath('profile.phone', '0900000000');

        $this->assertSame('RD'.str_pad((string) $staff->id, 6, '0', STR_PAD_LEFT), $staff->fresh()->readerProfile->reader_code);
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
