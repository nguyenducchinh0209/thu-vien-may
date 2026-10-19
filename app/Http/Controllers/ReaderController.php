<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReaderRequest;
use App\Http\Requests\UpdateReaderRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReaderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $readers = User::query()
            ->where('role', 'Độc giả')
            ->with('readerProfile')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('readerProfile', fn ($profile) => $profile
                            ->where('reader_code', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($readers);
    }

    public function store(StoreReaderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $profileData = $this->profileData($validated);
        unset($validated['phone'], $validated['address'], $validated['date_of_birth'], $validated['avatar']);
        $validated['email'] = mb_strtolower($validated['email']);

        if ($request->hasFile('avatar')) {
            $profileData['avatar_path'] = $request->file('avatar')->store('reader-avatars', 'public');
        }

        $reader = DB::transaction(function () use ($validated, $profileData): User {
            $reader = User::create($validated + ['role' => 'Độc giả']);
            $reader->readerProfile()->update($profileData);

            return $reader;
        });

        return response()->json([
            'message' => 'Tạo độc giả thành công.',
            'reader' => $reader->load('readerProfile'),
        ], 201);
    }

    public function show(User $reader): JsonResponse
    {
        $this->ensureReader($reader);

        return response()->json(['reader' => $reader->load('readerProfile')]);
    }

    public function update(UpdateReaderRequest $request, User $reader): JsonResponse
    {
        $this->ensureReader($reader);

        $validated = $request->validated();
        $profileData = $this->profileData($validated);
        unset($validated['phone'], $validated['address'], $validated['date_of_birth'], $validated['avatar']);
        if (isset($validated['email'])) {
            $validated['email'] = mb_strtolower($validated['email']);
        }
        $oldAvatarPath = $reader->readerProfile?->avatar_path;

        if ($request->hasFile('avatar')) {
            $profileData['avatar_path'] = $request->file('avatar')->store('reader-avatars', 'public');
        }

        DB::transaction(function () use ($reader, $validated, $profileData): void {
            $reader->update($validated);
            $reader->readerProfile()->updateOrCreate([], $profileData);
        });

        if (isset($profileData['avatar_path']) && $oldAvatarPath) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        return response()->json([
            'message' => 'Cập nhật độc giả thành công.',
            'reader' => $reader->fresh()->load('readerProfile'),
        ]);
    }

    public function destroy(User $reader): JsonResponse
    {
        $this->ensureReader($reader);
        $reader->delete();

        return response()->json(['message' => 'Đã xóa độc giả.']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function profileData(array $validated): array
    {
        return array_intersect_key($validated, array_flip(['phone', 'address', 'date_of_birth']));
    }

    private function ensureReader(User $reader): void
    {
        abort_unless($reader->role === 'Độc giả', 404);
    }
}
