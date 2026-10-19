<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('book_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['book_cart_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_cart_items');
        Schema::dropIfExists('book_carts');
    }
};
