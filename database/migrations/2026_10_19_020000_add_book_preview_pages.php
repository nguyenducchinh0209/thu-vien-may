<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->json('preview_pages')->nullable()->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropColumn('preview_pages');
        });
    }
};
