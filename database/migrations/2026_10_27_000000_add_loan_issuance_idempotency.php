<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('issuance_key', 100)->nullable()->unique();
            $table->char('issuance_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropUnique(['issuance_key']);
            $table->dropColumn(['issuance_key', 'issuance_fingerprint']);
        });
    }
};
