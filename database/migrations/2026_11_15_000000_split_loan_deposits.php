<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_deposits', function (Blueprint $table): void {
            $table->decimal('loss_protection_amount', 12, 2)->default(0);
            $table->decimal('book_holding_amount', 12, 2)->default(0);
        });

        foreach (DB::table('loan_deposits')->join('loan_items', 'loan_items.loan_id', '=', 'loan_deposits.loan_id')
            ->select('loan_deposits.id', 'loan_deposits.amount', DB::raw('SUM(loan_items.quantity) as copies'))
            ->groupBy('loan_deposits.id', 'loan_deposits.amount')->get() as $deposit) {
            $split = (float) $deposit->amount >= 100000 * (int) $deposit->copies;
            DB::table('loan_deposits')->where('id', $deposit->id)->update([
                'loss_protection_amount' => $split ? 70000 * (int) $deposit->copies : 0,
                'book_holding_amount' => $split ? 30000 * (int) $deposit->copies : 0,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('loan_deposits', function (Blueprint $table): void {
            $table->dropColumn(['loss_protection_amount', 'book_holding_amount']);
        });
    }
};
