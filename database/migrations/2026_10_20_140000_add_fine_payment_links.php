<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_quotes', function (Blueprint $table): void {
            $table->foreignId('fine_id')->nullable()->constrained('fines')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('fine_id')->nullable()->index()->constrained('fines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('fine_id')->exists()
            || DB::table('payment_quotes')->whereNotNull('fine_id')->exists()) {
            throw new RuntimeException('Cannot remove fine payment links while payment records exist.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fine_id');
        });

        Schema::table('payment_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fine_id');
        });
    }
};
