<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reader_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['book_id', 'status', 'created_at']);
        });

        Schema::create('fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_return_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reader_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('overdue_days');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('daily_rate');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('unpaid')->index();
            $table->foreignId('waived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('waiver_reason')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('book_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->unique(['book_id', 'user_id']);
            $table->index(['book_id', 'created_at']);
        });

        Schema::create('book_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['book_id', 'created_at']);
        });

        Schema::create('book_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('author_name')->nullable();
            $table->string('publisher')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('staff_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_suggestions');
        Schema::dropIfExists('book_comments');
        Schema::dropIfExists('book_reviews');
        Schema::dropIfExists('fines');
        Schema::dropIfExists('reservations');
    }
};
