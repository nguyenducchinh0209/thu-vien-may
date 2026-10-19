<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = User::query()
            ->with('readerProfile')
            ->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request): void {
                $query->where('name', 'like', '%'.$request->string('q')->trim().'%')
                    ->orWhere('email', 'like', '%'.$request->string('q')->trim().'%');
            }))
            ->orderBy('name')
            ->paginate(max(1, min($request->integer('per_page', 20), 50)));

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['Admin', 'Thủ thư', 'Độc giả'])],
            'is_locked' => ['sometimes', 'boolean'],
        ]);
        $data['email'] = mb_strtolower($data['email']);
        $account = User::create($data);

        return response()->json(['message' => 'Đã tạo tài khoản.', 'account' => $account], 201);
    }

    public function update(Request $request, User $account): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', Rule::in(['Admin', 'Thủ thư', 'Độc giả'])],
            'is_locked' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($account->is($request->user()) && (($data['is_locked'] ?? false) || isset($data['role']) && $data['role'] !== 'Admin')) {
            throw ValidationException::withMessages(['account' => 'Không thể khóa hoặc hạ quyền tài khoản Admin đang đăng nhập.']);
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }
        if (array_key_exists('password', $data) && $data['password'] === null) {
            unset($data['password']);
        }
        DB::transaction(fn () => $account->update($data));

        return response()->json(['message' => 'Đã cập nhật tài khoản.', 'account' => $account->fresh()]);
    }
}
