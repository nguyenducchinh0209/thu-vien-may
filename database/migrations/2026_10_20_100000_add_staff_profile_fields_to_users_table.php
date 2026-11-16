<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('avatar_path')->nullable();
        });

        DB::table('users')
            ->where('role', '<>', 'Độc giả')
            ->orderBy('id')
            ->eachById(function ($user): void {
                $profile = DB::table('reader_profiles')->where('user_id', $user->id)->first();
                if (! $profile) {
                    return;
                }

                $profileData = [];
                foreach (['phone', 'address', 'date_of_birth', 'avatar_path'] as $field) {
                    if ($user->{$field} === null && $profile->{$field} !== null) {
                        $profileData[$field] = $profile->{$field};
                    }
                }
                if ($profileData) {
                    DB::table('users')->where('id', $user->id)->update($profileData);
                }
            });
    }

    public function down(): void
    {
        $hasProfileData = DB::table('users')->where(function ($query): void {
            $query->whereNotNull('phone')
                ->orWhereNotNull('address')
                ->orWhereNotNull('date_of_birth')
                ->orWhereNotNull('avatar_path');
        })->exists();

        if ($hasProfileData) {
            throw new RuntimeException('Cannot remove staff profile fields while profile data exists.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address', 'date_of_birth', 'avatar_path']);
        });
    }
};
