<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('reader_name_snapshot')->nullable();
            $table->string('issuer_name_snapshot')->nullable();
        });

        Schema::table('loan_items', function (Blueprint $table): void {
            $table->string('book_title_snapshot')->nullable();
            $table->string('book_code_snapshot', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_items', fn (Blueprint $table) => $table->dropColumn(['book_title_snapshot', 'book_code_snapshot']));
        Schema::table('loans', fn (Blueprint $table) => $table->dropColumn(['reader_name_snapshot', 'issuer_name_snapshot']));
    }
};
