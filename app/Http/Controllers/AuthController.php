<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Vui lòng nhập họ và tên.',
            'name.min' => 'Họ và tên cần có ít nhất 2 ký tự.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email chưa đúng định dạng.',
            'email.unique' => 'Email này đã được đăng ký.',
            'password.min' => 'Mật khẩu cần có ít nhất 8 ký tự.',
            'password.confirmed' => 'Mật khẩu nhập lại chưa khớp.',
        ]);

        $user = DB::transaction(fn () => User::create([
            'name' => $validated['name'],
            'email' => mb_strtolower($validated['email']),
            'password' => $validated['password'],
            'role' => 'Độc giả',
        ]));

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version_web', $user->auth_version);

        return response()->json([
            'message' => 'Tạo tài khoản thành công.',
            'user' => $this->userPayload($user),
            'csrf_token' => $request->session()->token(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email chưa đúng định dạng.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ]);

        $credentials['email'] = mb_strtolower($credentials['email']);
        $authenticated = Auth::attempt($credentials, $request->boolean('remember'));
        if (! $authenticated || Auth::user()?->is_locked) {
            $locked = $authenticated && (bool) Auth::user()?->is_locked;
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => $locked ? 'Tài khoản đã bị khóa.' : 'Email hoặc mật khẩu chưa chính xác.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('auth_version_web', $request->user()->auth_version);

        return response()->json([
            'message' => 'Đăng nhập thành công.',
            'user' => $this->userPayload($request->user()),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Bạn đã đăng xuất.',
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => mb_strtolower($request->string('email')->toString())]);

        return response()->json(['message' => 'Nếu email đã được đăng ký, hướng dẫn đặt lại mật khẩu sẽ được gửi.']);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset([
            ...$validated,
            'email' => mb_strtolower($validated['email']),
        ], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'auth_version' => $user->auth_version + 1,
                ])->save();
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }

        return redirect('/#login')->with('status', 'Mật khẩu đã được đặt lại. Vui lòng đăng nhập.');
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load('readerProfile');
        return response()->json([
            'user' => $this->userPayload($user),
            'profile' => $this->profilePayload($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:5000'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);
        $profileData = array_intersect_key($validated, array_flip(['phone', 'address', 'date_of_birth', 'gender']));
        $oldAvatar = $user->role === 'Độc giả'
            ? $user->readerProfile?->avatar_path
            : $user->avatar_path;
        if ($request->hasFile('avatar')) {
            $profileData['avatar_path'] = $request->file('avatar')->store('reader-avatars', 'public');
        }
        DB::transaction(function () use ($user, $validated, $profileData): void {
            $user->update(['name' => $validated['name'], 'email' => mb_strtolower($validated['email'])]);

            if ($user->role === 'Độc giả') {
                $profile = $user->readerProfile()->firstOrNew();
                if (! $profile->exists) {
                    $profile->reader_code = 'RD'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
                }
                $profile->fill($profileData)->save();
            } else {
                $user->update($profileData);
            }
        });
        if (isset($profileData['avatar_path']) && $oldAvatar && $oldAvatar !== $profileData['avatar_path']) {
            Storage::disk('public')->delete($oldAvatar);
        }
        $user->refresh()->load('readerProfile');

        return response()->json([
            'message' => 'Đã cập nhật hồ sơ.',
            'user' => $this->userPayload($user),
            'profile' => $this->profilePayload($user),
        ]);
    }

    private function profilePayload(User $user): array
    {
        if ($user->role === 'Độc giả') {
            $profile = $user->readerProfile?->toArray() ?? [];
            $avatarPath = $user->readerProfile?->avatar_path;
        } else {
            $profile = $user->only(['phone', 'address', 'date_of_birth', 'avatar_path', 'gender']);
            $avatarPath = $user->avatar_path;
        }

        $profile['date_of_birth'] = $user->role === 'Độc giả'
            ? $user->readerProfile?->date_of_birth?->format('Y-m-d')
            : $user->date_of_birth?->format('Y-m-d');
        $profile['avatar_url'] = $avatarPath ? Storage::disk('public')->url($avatarPath) : null;

        return $profile;
    }

    private function userPayload(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role']);
    }
}
