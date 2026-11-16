<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->string('reason', 500);
            $table->string('receipt_reference', 150);
            $table->timestamp('refunded_at')->index();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('payment_refunds')->exists()) {
            throw new RuntimeException('Cannot remove the refund table while it contains receipts.');
        }

        Schema::dropIfExists('payment_refunds');
    }
};
