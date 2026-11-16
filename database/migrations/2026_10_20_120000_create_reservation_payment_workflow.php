<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('payment_status', 20)->nullable()->index();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
        });

        Schema::create('payment_quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('reader_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->restrictOnDelete();
            $table->string('plan_id', 40)->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->char('currency', 3);
            $table->json('items_snapshot');
            $table->json('allowed_methods');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['reader_id', 'purpose', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('reader_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('quote_id')->unique()->constrained('payment_quotes')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->unique()->constrained('reservations')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('method', 30);
            $table->string('status', 20)->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('idempotency_key', 100);
            $table->char('request_fingerprint', 64);
            $table->json('transfer_snapshot')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('receipt_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['reader_id', 'idempotency_key']);
            $table->index(['purpose', 'status', 'created_at']);
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('event_type', 40);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('tier', 20);
            $table->string('status', 20)->index();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->index();
            $table->foreignUlid('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->foreignId('reservation_id')->nullable()->unique()->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->exists() || DB::table('payment_quotes')->exists()
            || DB::table('memberships')->exists() || DB::table('payment_events')->exists()
            || DB::table('reservations')->whereNotNull('payment_status')->exists()
            || DB::table('loans')->whereNotNull('payment_id')->exists()
            || DB::table('loans')->whereNotNull('reservation_id')->exists()) {
            throw new RuntimeException('Cannot remove payment workflow tables while payment records or links exist.');
        }

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('reservation_id');
        });

        Schema::dropIfExists('memberships');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_quotes');

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['payment_status', 'payment_method', 'amount', 'currency']);
        });
    }
};
