<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_locked')->default(false)->after('role');
        });

        Schema::table('fines', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('waived_at');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->restrictOnDelete();
        });

        Schema::create('fine_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30)->default('manual');
            $table->string('receipt_note', 500)->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['fine_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_payments');
        Schema::table('fines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn('paid_at');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_locked');
        });
    }
};
