<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reader_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('reader_code', 32)->unique();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamps();
        });

        DB::table('users')->where('role', 'Độc giả')->orderBy('id')->eachById(function ($user) {
            DB::table('reader_profiles')->insert([
                'user_id' => $user->id,
                'reader_code' => 'RD'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->text('biography')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('book_code', 32)->unique();
            $table->string('title')->index();
            $table->string('isbn', 20)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('publisher')->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('edition')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->unsignedInteger('total_quantity')->default(0);
            $table->decimal('rental_fee', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('author_book', function (Blueprint $table) {
            $table->foreignId('author_id')->constrained()->restrictOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->primary(['author_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_book');
        Schema::dropIfExists('books');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('reader_profiles');
    }
};
