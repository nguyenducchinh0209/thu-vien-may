<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reader_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('due_at');
            $table->timestamp('returned_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('loan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->decimal('rental_fee_per_copy', 12, 2);
            $table->timestamps();
            $table->unique(['loan_id', 'book_id']);
            $table->index('book_id');
        });

        Schema::create('loan_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->timestamps();
        });

        Schema::create('loan_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_return_id')->constrained('loan_returns')->cascadeOnDelete();
            $table->foreignId('loan_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_return_items');
        Schema::dropIfExists('loan_returns');
        Schema::dropIfExists('loan_items');
        Schema::dropIfExists('loans');
    }
};
