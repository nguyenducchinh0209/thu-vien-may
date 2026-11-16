<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasLostQuantity = Schema::hasColumn('loan_items', 'lost_quantity');
        $hasWrittenOffQuantity = Schema::hasColumn('loan_items', 'written_off_quantity');

        if (!$hasLostQuantity || !$hasWrittenOffQuantity) {
            Schema::table('loan_items', function (Blueprint $table) use ($hasLostQuantity, $hasWrittenOffQuantity): void {
                if (!$hasLostQuantity) {
                    $table->unsignedInteger('lost_quantity')->default(0);
                }

                if (!$hasWrittenOffQuantity) {
                    $table->unsignedInteger('written_off_quantity')->default(0);
                }
            });
        }

        Schema::table('fines', function (Blueprint $table): void {
            $table->dropForeign(['loan_return_item_id']);
        });

        Schema::table('fines', function (Blueprint $table): void {
            $table->dropUnique(['loan_return_item_id']);
            $table->unsignedBigInteger('loan_return_item_id')->nullable()->change();
            $table->index('loan_return_item_id');
            $table->foreign('loan_return_item_id')->references('id')->on('loan_return_items')->restrictOnDelete();
        });

        Schema::table('fines', function (Blueprint $table): void {
            $table->foreignId('loan_item_id')->nullable()->after('loan_return_item_id')->constrained()->restrictOnDelete();
            $table->string('fine_code', 32)->nullable()->unique()->after('id');
            $table->string('type', 32)->default('overdue')->index();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('request_key', 80)->nullable()->unique();
            $table->index(['loan_id', 'type', 'status']);
        });

        foreach (DB::table('fines')->whereNull('fine_code')->orderBy('id')->get(['id', 'overdue_days']) as $fine) {
            DB::table('fines')->where('id', $fine->id)->update([
                'fine_code' => 'F'.str_pad((string) $fine->id, 9, '0', STR_PAD_LEFT),
                'type' => 'overdue',
                'reason' => 'Trả trễ '.$fine->overdue_days.' ngày',
            ]);
        }

        Schema::create('loan_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->string('receipt_note', 500)->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        Schema::create('loan_deposit_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_deposit_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('deposit_used', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('refund_method', 30)->nullable();
            $table->decimal('additional_received', 12, 2)->default(0);
            $table->string('additional_method', 30)->nullable();
            $table->string('receipt_note', 500)->nullable();
            $table->foreignId('settled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('settled_at');
            $table->timestamps();
        });

        Schema::create('loan_deposit_fine_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained('loan_deposit_settlements')->cascadeOnDelete();
            $table->foreignId('fine_id')->constrained()->restrictOnDelete();
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->decimal('additional_amount', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['settlement_id', 'fine_id']);
        });

        Schema::create('reading_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('last_chapter')->default(1);
            $table->timestamp('last_read_at');
            $table->timestamps();
            $table->unique(['user_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
        Schema::dropIfExists('loan_deposit_fine_allocations');
        Schema::dropIfExists('loan_deposit_settlements');
        Schema::dropIfExists('loan_deposits');

        Schema::table('fines', function (Blueprint $table): void {
            $table->dropIndex(['loan_id', 'type', 'status']);
            $table->dropUnique(['request_key']);
            $table->dropUnique(['fine_code']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('loan_item_id');
            $table->dropColumn(['fine_code', 'type', 'reason']);
            $table->unsignedBigInteger('loan_return_item_id')->nullable(false)->change();
            $table->unique('loan_return_item_id');
        });

        Schema::table('loan_items', function (Blueprint $table): void {
            $table->dropColumn(['lost_quantity', 'written_off_quantity']);
        });
    }
};