<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('gender', 30)->nullable();
        });

        Schema::table('reader_profiles', function (Blueprint $table): void {
            $table->string('gender', 30)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('gender')->exists()
            || DB::table('reader_profiles')->whereNotNull('gender')->exists()) {
            throw new RuntimeException('Cannot remove gender fields while profile data exists.');
        }

        Schema::table('reader_profiles', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });
    }
};
