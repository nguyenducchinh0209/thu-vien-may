<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_returns', function (Blueprint $table): void {
            $table->string('return_key', 80)->nullable();
            $table->char('return_fingerprint', 64)->nullable();
            $table->unique(['loan_id', 'return_key']);
        });
    }

    public function down(): void
    {
        Schema::table('loan_returns', function (Blueprint $table): void {
            $table->dropUnique(['loan_id', 'return_key']);
            $table->dropColumn(['return_key', 'return_fingerprint']);
        });
    }
};
