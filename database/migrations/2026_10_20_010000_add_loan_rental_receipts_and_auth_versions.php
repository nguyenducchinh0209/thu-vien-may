<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('auth_version')->default(0);
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->decimal('rental_fee_total', 12, 2)->nullable();
            $table->decimal('rental_fee_received', 12, 2)->nullable();
            $table->timestamp('rental_fee_received_at')->nullable();
            $table->foreignId('rental_fee_received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('rental_fee_payment_method', 30)->nullable();
            $table->string('rental_fee_receipt_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rental_fee_received_by');
            $table->dropColumn([
                'rental_fee_total', 'rental_fee_received', 'rental_fee_received_at',
                'rental_fee_payment_method', 'rental_fee_receipt_note',
            ]);
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_version'));
    }
};
